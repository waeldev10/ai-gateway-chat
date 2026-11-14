<?php

namespace App\Livewire\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Livewire\Auth\Concerns\HandlesRateLimiting;
use App\Services\Auth\RegisterService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RegisterForm extends Component
{
    use HandlesRateLimiting;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(RegisterService $registerService): void
    {
        $this->email = strtolower(trim($this->email));

        $limits = $this->resolveRateLimits('register');

        try {
            $validated = $this->validate((new RegisterRequest)->rules());

            $this->ensureRateLimitNotExceeded($limits);

            $this->hitRateLimits($limits);

            $registerService->registerAndAuthenticate([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            if ($this->rateLimitExceeded($limits)) {
                $this->dispatch(
                    'toast',
                    type: 'error',
                    title: 'تعذر إنشاء الحساب',
                    message: 'لقد حاولت 5 مرات، الرجاء الانتظار.',
                );
            }

            throw $e;
        }

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'تم إنشاء الحساب',
            'message' => 'مرحباً بك في بوابة الذكاء الاصطناعي.',
        ]);

        $this->redirect(route('conversations.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register-form');
    }
}
