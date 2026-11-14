<?php

namespace App\Livewire\Persona;

use App\Http\Requests\Api\V1\Personas\StorePersonaRequest;
use App\Http\Requests\Api\V1\Personas\UpdatePersonaRequest;
use App\Models\AiPersona;
use App\Services\Persona\AiPersonaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Persona settings: the read-only system default plus the user's own
 * personas (create up to the configured limit / edit / toggle / delete).
 *
 * Only primitives are kept in public state, so Livewire never hydrates or
 * dehydrates Eloquent models between requests. The read-side data is exposed
 * through computed properties, which are evaluated lazily, cached for the
 * current request, and never serialized into the component snapshot.
 */
class PersonaSettings extends Component
{
    public string $name = '';

    public string $system_prompt = '';

    public ?string $editingId = null;

    public string $editingName = '';

    public string $editingSystemPrompt = '';

    public ?string $confirmingId = null;

    public function create(AiPersonaService $personas): void
    {
        $rules = (new StorePersonaRequest)->rules();

        $validated = $this->validate([
            'name' => $rules['name'],
            'system_prompt' => $rules['system_prompt'],
        ]);

        try {
            $personas->create(Auth::user(), $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذّر الإنشاء', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        $this->reset(['name', 'system_prompt']);

        $this->dispatch('toast', type: 'success', title: 'تم الحفظ', message: 'تم إنشاء الشخصية بنجاح.');
    }

    public function startEdit(string $id): void
    {
        $persona = $this->ownedPersona($id);

        $this->editingId = (string) $persona->getKey();
        $this->editingName = (string) $persona->name;
        $this->editingSystemPrompt = (string) $persona->system_prompt;
        $this->confirmingId = null;
    }

    public function update(AiPersonaService $personas): void
    {
        if ($this->editingId === null) {
            return;
        }

        $persona = $this->ownedPersona($this->editingId);

        $this->authorize('update', $persona);

        $rules = (new UpdatePersonaRequest)->rules();

        $validated = $this->validate([
            'editingName' => $rules['name'],
            'editingSystemPrompt' => $rules['system_prompt'],
        ]);

        try {
            $personas->update(Auth::user(), $persona, [
                'name' => $validated['editingName'],
                'system_prompt' => $validated['editingSystemPrompt'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذّر الحفظ', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        $this->cancelEdit();

        $this->dispatch('toast', type: 'success', title: 'تم الحفظ', message: 'تم تحديث الشخصية بنجاح.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingName', 'editingSystemPrompt']);
        $this->resetValidation();
    }

    public function toggle(string $id, AiPersonaService $personas): void
    {
        $persona = $this->ownedPersona($id);

        $activate = ! (bool) $persona->is_active;

        $this->authorize($activate ? 'activate' : 'deactivate', $persona);

        try {
            $persona = $activate
                ? $personas->activate(Auth::user(), $persona)
                : $personas->deactivate(Auth::user(), $persona);
        } catch (AuthorizationException $e) {
            report($e);

            $this->confirmingId = null;

            $this->dispatch('toast', type: 'error', title: 'غير مصرّح', message: 'لا يمكنك تعديل هذه الشخصية.');

            return;
        } catch (Throwable $e) {
            report($e);

            $this->confirmingId = null;

            $this->dispatch('toast', type: 'error', title: 'تعذّر التحديث', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        $this->confirmingId = null;

        $this->dispatch(
            'toast',
            type: 'success',
            title: 'تم التحديث',
            message: $persona->is_active ? 'تم تفعيل الشخصية.' : 'تم تعطيل الشخصية.',
        );
    }

    /**
     * Two-step delete: the first call arms confirmation on the row, the
     * second call for the same id performs the deletion.
     */
    public function remove(string $id, AiPersonaService $personas): void
    {
        if ($this->confirmingId !== $id) {
            $this->confirmingId = $id;

            return;
        }

        $persona = $this->ownedPersona($id);

        $this->authorize('delete', $persona);

        try {
            $personas->delete(Auth::user(), $persona);
        } catch (AuthorizationException $e) {
            report($e);

            $this->confirmingId = null;

            $this->dispatch('toast', type: 'error', title: 'غير مصرّح', message: 'لا يمكنك حذف هذه الشخصية.');

            return;
        } catch (Throwable $e) {
            report($e);

            $this->confirmingId = null;

            $this->dispatch('toast', type: 'error', title: 'تعذّر الحذف', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        $this->confirmingId = null;

        $this->dispatch('toast', type: 'success', title: 'تم الحذف', message: 'تم حذف الشخصية نهائيًا.');
    }

    #[Computed]
    public function systemDefault(): AiPersona
    {
        return app(AiPersonaService::class)->systemDefault();
    }

    /**
     * @return Collection<int, AiPersona>
     */
    #[Computed]
    public function personas(): Collection
    {
        return app(AiPersonaService::class)->customFor(Auth::user());
    }

    #[Computed]
    public function maxCustom(): int
    {
        return app(AiPersonaService::class)->maxCustomPersonas();
    }

    #[Computed]
    public function canCreate(): bool
    {
        return $this->personas->count() < $this->maxCustom;
    }

    #[Computed]
    public function maxNameLength(): int
    {
        return max(1, (int) config('ai.personas.max_name_length', 100));
    }

    #[Computed]
    public function maxPromptLength(): int
    {
        return max(1, (int) config('ai.personas.max_prompt_length', 8000));
    }

    public function render(): View
    {
        return view('livewire.persona.persona-settings');
    }

    private function ownedPersona(string $id): AiPersona
    {
        return Auth::user()->aiPersonas()->findOrFail($id);
    }
}
