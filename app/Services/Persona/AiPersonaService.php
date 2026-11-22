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

/** يدير الشخصيات الافتراضية والمخصصة، بما يشمل التحقق والصلاحيات والتخزين المؤقت واختيار شخصية المحادثة */
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

    public function customFor(User $user): Collection
    {
        return AiPersona::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'user_id', 'name', 'system_prompt', 'is_active', 'is_default']);
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

            if ($isActive) {
                $user->aiPersonas()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
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
            if (($changes['is_active'] ?? false) === true) {
                return $this->activateExclusively($user, $persona, $changes);
            }

            $persona->update($changes);
            $this->forgetActiveCustom((string) $user->getKey());
        }

        return $persona;
    }

    public function activate(User $user, AiPersona $persona): AiPersona
    {
        $this->assertMutable($user, $persona);

        return $this->activateExclusively($user, $persona, ['is_active' => true]);
    }

    public function deactivate(User $user, AiPersona $persona): AiPersona
    {
        $this->assertMutable($user, $persona);

        if ($persona->is_active) {
            DB::transaction(function () use ($user, $persona): void {
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                $lockedPersona = $user->aiPersonas()
                    ->whereKey($persona->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedPersona->update(['is_active' => false]);
            });

            $this->forgetActiveCustom((string) $user->getKey());
        }

        return $persona->refresh();
    }

    public function delete(User $user, AiPersona $persona): void
    {
        $this->assertMutable($user, $persona);

        $persona->delete();

        $this->forgetActiveCustom((string) $user->getKey());
    }

    public function resolveForConversation(Conversation $conversation, ?AiPersona $selected = null): AiPersona
    {
        if ($selected !== null) {
            return $this->selectableFor($conversation->user, $selected);
        }

        $active = $this->activeCustomPersonas((string) $conversation->user_id)->first();

        return $active ?? $this->systemDefault();
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

    private function forgetActiveCustom(string $userId): void
    {
        Cache::forget($this->activeCustomCacheKey($userId));
    }

    private function activeCustomCacheKey(string $userId): string
    {
        return 'ai.personas.active.'.$userId;
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

    private function activateExclusively(User $user, AiPersona $persona, array $attributes): AiPersona
    {
        DB::transaction(function () use ($user, $persona, $attributes): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $user->aiPersonas()
                ->whereKeyNot($persona->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $lockedPersona = $user->aiPersonas()
                ->whereKey($persona->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPersona->update($attributes);
        });

        $this->forgetActiveCustom((string) $user->getKey());

        return $persona->refresh();
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
