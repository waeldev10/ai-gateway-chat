<?php

namespace App\Services\Persona;

use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiPersonaService
{
    private const CACHE_TTL_SECONDS = 3600;

    private const SYSTEM_DEFAULT_CACHE_KEY = 'ai.personas.system-default';

    public function visibleFor(User $user): Collection
    {
        $this->systemDefault();

        return AiPersona::query()
            ->where(function (Builder $query) use ($user): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user->getKey());
            })
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function systemDefault(): AiPersona
    {
        $cached = Cache::get(self::SYSTEM_DEFAULT_CACHE_KEY);

        if (is_array($cached)) {
            return $this->hydratePersona($cached);
        }

        $persona = AiPersona::query()->firstOrCreate(
            [
                'user_id' => null,
                'is_default' => true,
            ],
            [
                'name' => AiPersona::SYSTEM_DEFAULT_NAME,
                'system_prompt' => AiPersona::SYSTEM_DEFAULT_PROMPT,
                'is_active' => true,
            ],
        );

        if (! $persona->is_active) {
            $persona->update(['is_active' => true]);
        }

        Cache::put(self::SYSTEM_DEFAULT_CACHE_KEY, $persona->getAttributes(), self::CACHE_TTL_SECONDS);

        return $persona;
    }

    public function create(User $user, array $attributes): AiPersona
    {
        $name = $this->normalizeName((string) ($attributes['name'] ?? ''));
        $systemPrompt = $this->normalizePrompt((string) ($attributes['system_prompt'] ?? ''));
        $isActive = (bool) ($attributes['is_active'] ?? true);

        $this->assertValidName($name);
        $this->assertValidPrompt($systemPrompt);

        $persona = DB::transaction(function () use ($user, $name, $systemPrompt, $isActive): AiPersona {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($user->aiPersonas()->count() >= $this->maxCustomPersonas()) {
                throw ValidationException::withMessages([
                    'name' => 'لا يمكن إنشاء أكثر من '.$this->maxCustomPersonas().' شخصيات مخصصة.',
                ]);
            }

            return $user->aiPersonas()->create([
                'name' => $name,
                'system_prompt' => $systemPrompt,
                'is_active' => $isActive,
                'is_default' => false,
            ]);
        });

        $this->forgetActiveCustom((string) $user->getKey());

        return $persona;
    }

    public function update(User $user, AiPersona $persona, array $attributes): AiPersona
    {
        $this->assertMutable($user, $persona);

        $changes = [];

        if (array_key_exists('name', $attributes)) {
            $name = $this->normalizeName((string) $attributes['name']);
            $this->assertValidName($name);
            $changes['name'] = $name;
        }

        if (array_key_exists('system_prompt', $attributes)) {
            $systemPrompt = $this->normalizePrompt((string) $attributes['system_prompt']);
            $this->assertValidPrompt($systemPrompt);
            $changes['system_prompt'] = $systemPrompt;
        }

        if (array_key_exists('is_active', $attributes)) {
            $changes['is_active'] = (bool) $attributes['is_active'];
        }

        if ($changes !== []) {
            $persona->update($changes);

            $this->forgetPersonaCaches((string) $user->getKey(), (string) $persona->getKey());
        }

        return $persona;
    }

    public function activate(User $user, AiPersona $persona): AiPersona
    {
        $this->assertMutable($user, $persona);

        if (! $persona->is_active) {
            $persona->update(['is_active' => true]);

            $this->forgetPersonaCaches((string) $user->getKey(), (string) $persona->getKey());
        }

        return $persona;
    }

    public function deactivate(User $user, AiPersona $persona): AiPersona
    {
        $this->assertMutable($user, $persona);

        if ($persona->is_active) {
            $persona->update(['is_active' => false]);

            $this->forgetPersonaCaches((string) $user->getKey(), (string) $persona->getKey());
        }

        return $persona;
    }

    public function delete(User $user, AiPersona $persona): void
    {
        $this->assertMutable($user, $persona);

        $persona->delete();

        $this->forgetPersonaCaches((string) $user->getKey(), (string) $persona->getKey());
    }

    public function resolveForConversation(Conversation $conversation, ?AiPersona $selected = null): AiPersona
    {
        if ($selected !== null) {
            return $this->selectableFor($conversation->user, $selected);
        }

        $active = $this->activeCustomPersonas((string) $conversation->user_id)->first();

        return $active ?? $this->systemDefault();
    }

    public function resolveForRequest(User $user, ?string $personaId): ?AiPersona
    {
        $personaId = is_string($personaId) ? trim($personaId) : '';

        if ($personaId === '') {
            return null;
        }

        if (! Str::isUlid($personaId)) {
            throw ValidationException::withMessages([
                'persona_id' => 'الشخصية المحددة غير متاحة.',
            ]);
        }

        $persona = $this->findForUser($user, $personaId);

        if ($persona === null || ! $this->isVisibleTo($user, $persona)) {
            throw ValidationException::withMessages([
                'persona_id' => 'الشخصية المحددة غير متاحة.',
            ]);
        }

        if (! $persona->isSystemDefault() && ! $persona->is_active) {
            throw ValidationException::withMessages([
                'persona_id' => 'الشخصية المحددة غير متاحة.',
            ]);
        }

        return $persona;
    }

    public function selectableFor(User $user, AiPersona $persona): AiPersona
    {
        if (! $this->isVisibleTo($user, $persona)) {
            throw new AuthorizationException('The selected persona is not available.');
        }

        if (! $persona->isSystemDefault() && ! $persona->is_active) {
            throw new AuthorizationException('The selected persona is not active.');
        }

        return $persona;
    }

    public function isVisibleTo(User $user, AiPersona $persona): bool
    {
        return $persona->isSystemDefault() || $persona->isOwnedBy($user);
    }

    public function maxCustomPersonas(): int
    {
        return max(1, (int) config('ai.personas.max_custom', 3));
    }

    private function activeCustomPersonas(string $userId): Collection
    {
        $key = $this->activeCustomCacheKey($userId);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return (new Collection($cached))
                ->map(fn (array $attributes): AiPersona => $this->hydratePersona($attributes));
        }

        $personas = AiPersona::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        Cache::put(
            $key,
            $personas->map(fn (AiPersona $persona): array => $persona->getAttributes())->all(),
            self::CACHE_TTL_SECONDS,
        );

        return $personas;
    }

    private function findForUser(User $user, string $personaId): ?AiPersona
    {
        $key = $this->lookupCacheKey((string) $user->getKey(), $personaId);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $this->hydratePersona($cached);
        }

        $persona = AiPersona::query()->whereKey($personaId)->first();

        if ($persona !== null && $this->isVisibleTo($user, $persona)) {
            Cache::put($key, $persona->getAttributes(), self::CACHE_TTL_SECONDS);
        }

        return $persona;
    }

    private function forgetPersonaCaches(string $userId, string $personaId): void
    {
        $this->forgetActiveCustom($userId);

        Cache::forget($this->lookupCacheKey($userId, $personaId));
    }

    private function forgetActiveCustom(string $userId): void
    {
        Cache::forget($this->activeCustomCacheKey($userId));
    }

    private function activeCustomCacheKey(string $userId): string
    {
        return 'ai.personas.active.'.$userId;
    }

    private function lookupCacheKey(string $userId, string $personaId): string
    {
        return 'ai.personas.lookup.'.$userId.'.'.$personaId;
    }

    private function hydratePersona(array $attributes): AiPersona
    {
        return (new AiPersona)->newFromBuilder($attributes);
    }

    private function assertMutable(User $user, AiPersona $persona): void
    {
        if ($persona->isSystemDefault()) {
            throw new AuthorizationException('The system default persona cannot be modified.');
        }

        if (! $persona->isOwnedBy($user)) {
            throw new AuthorizationException('You do not own this persona.');
        }
    }

    private function normalizeName(string $name): string
    {
        return Str::squish(trim($name));
    }

    private function normalizePrompt(string $systemPrompt): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $systemPrompt));
    }

    private function assertValidName(string $name): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'الاسم مطلوب.',
            ]);
        }

        $max = max(1, (int) config('ai.personas.max_name_length', 100));

        if (mb_strlen($name) > $max) {
            throw ValidationException::withMessages([
                'name' => 'يجب ألا يزيد الاسم عن '.$max.' حرفًا.',
            ]);
        }
    }

    private function assertValidPrompt(string $systemPrompt): void
    {
        if ($systemPrompt === '') {
            throw ValidationException::withMessages([
                'system_prompt' => 'تعليمات الشخصية مطلوبة.',
            ]);
        }

        $max = max(1, (int) config('ai.personas.max_prompt_length', 8000));

        if (mb_strlen($systemPrompt) > $max) {
            throw ValidationException::withMessages([
                'system_prompt' => 'يجب ألا تزيد تعليمات الشخصية عن '.$max.' حرفًا.',
            ]);
        }
    }
}
