<?php

namespace App\Livewire\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Livewire\Auth\Concerns\HandlesRateLimiting;
use App\Services\Auth\LoginService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
    use HandlesRateLimiting;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(LoginService $loginService): void
    {
        $this->email = strtolower(trim($this->email));

        $limits = $this->resolveRateLimits('login', ['email' => $this->email]);

        try {
            $validated = $this->validate((new LoginRequest)->rules());

            $this->ensureRateLimitNotExceeded($limits);

            $this->hitRateLimits($limits);

            $loginService->login([
                'email' => $validated['email'],
                'password' => $validated['password'],
            ], $this->remember);
        } catch (ValidationException $e) {
            $this->reset('password');

            if ($this->rateLimitExceeded($limits)) {
                $this->dispatch(
                    'toast',
                    type: 'error',
                    title: 'تعذر تسجيل الدخول',
                    message: 'لقد حاولت 5 مرات، الرجاء الانتظار.',
                );
            }

            throw $e;
        }

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'مرحباً بعودتك',
            'message' => 'تم تسجيل الدخول بنجاح.',
        ]);

        $this->redirect(route('conversations.create'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
