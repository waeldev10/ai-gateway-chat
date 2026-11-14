<?php

namespace App\Livewire\Profile;

use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Services\Auth\ChangePasswordService;
use App\Services\Auth\UpdateProfileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class ProfileForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
    }

    public function saveProfile(UpdateProfileService $profiles): void
    {
        $this->email = strtolower(trim($this->email));

        $request = UpdateProfileRequest::createFrom(request());
        $request->setUserResolver(fn () => Auth::user());

        $validated = $this->validate($request->rules());

        try {
            $user = $profiles->update(Auth::user(), $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذّر الحفظ', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        $this->name = (string) $user->name;
        $this->email = (string) $user->email;

        $this->dispatch(
            'profile-updated',
            name: $user->name,
            initial: (string) mb_substr($user->name ?? '', 0, 1),
            email: $user->email,
        );
        $this->dispatch('toast', type: 'success', title: 'تم الحفظ', message: 'تم تحديث بياناتك بنجاح.');
    }

    public function savePassword(ChangePasswordService $passwords): void
    {
        $validated = $this->validate((new ChangePasswordRequest)->rules());

        try {
            $changed = $passwords->change(
                Auth::user(),
                $validated['current_password'],
                $validated['password'],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذّر الحفظ', message: 'حدث خطأ غير متوقع. حاول مرة أخرى.');

            return;
        }

        if (! $changed) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.password'),
            ]);
        }

        $this->reset(['current_password', 'password', 'password_confirmation']);

        $this->dispatch('toast', type: 'success', title: 'تم التحديث', message: 'تم تغيير كلمة المرور بنجاح.');
    }

    public function render()
    {
        return view('livewire.profile.profile-form');
    }
}
