<?php

use App\Livewire\Auth\LoginForm;
use App\Livewire\Auth\RegisterForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('livewire login', function () {
    it('renders the login page with the livewire form', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('تسجيل الدخول')
            ->assertSeeLivewire('auth.login-form')
            ->assertSee('تذكرني');
    });

    it('authenticates valid credentials without a page reload', function () {
        $user = User::factory()->create();

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversations.create'));

        $this->assertAuthenticatedAs($user);

        expect(session('toast'))->toMatchArray([
            'type' => 'success',
            'title' => 'مرحباً بعودتك',
            'message' => 'تم تسجيل الدخول بنجاح.',
        ]);
    });

    it('rejects invalid credentials, keeps the email, and clears the password', function () {
        $user = User::factory()->create();

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email'])
            ->assertSet('email', $user->email)
            ->assertSet('password', '');

        $this->assertGuest();
    });

    it('validates empty input without authenticating', function () {
        Livewire::test(LoginForm::class)
            ->set('email', '')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['email', 'password']);

        $this->assertGuest();
    });

    it('throttles livewire login attempts using the registered limiter', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            Livewire::test(LoginForm::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['email']);
        }

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    });

    it('keeps the per-email limit independent on the same ip', function () {
        $first = User::factory()->create(['email' => 'first@example.com']);
        $second = User::factory()->create(['email' => 'second@example.com']);

        foreach (range(1, 5) as $attempt) {
            Livewire::test(LoginForm::class)
                ->set('email', $first->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['email']);
        }

        Livewire::test(LoginForm::class)
            ->set('email', $second->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversations.create'));

        $this->assertAuthenticatedAs($second);
    });

    it('enforces the per-ip login limit across different emails', function () {
        foreach (range(1, 4) as $index) {
            $email = "user{$index}@example.com";
            User::factory()->create(['email' => $email]);

            foreach (range(1, 5) as $attempt) {
                Livewire::test(LoginForm::class)
                    ->set('email', $email)
                    ->set('password', 'wrong-password')
                    ->call('login')
                    ->assertHasErrors(['email']);
            }
        }

        $last = User::factory()->create(['email' => 'last@example.com']);

        Livewire::test(LoginForm::class)
            ->set('email', $last->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    });

    it('shares the login throttle counters with the http route', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            Livewire::test(LoginForm::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['email']);
        }

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertTooManyRequests();

        $this->assertGuest();
    });
});

describe('livewire register', function () {
    it('renders the register page with the livewire form', function () {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('إنشاء حساب')
            ->assertSeeLivewire('auth.register-form');
    });

    it('registers, authenticates, and redirects without a page reload', function () {
        Livewire::test(RegisterForm::class)
            ->set('name', 'مستخدم تجريبي')
            ->set('email', 'livewire-new@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('conversations.create'));

        $user = User::query()->where('email', 'livewire-new@example.com')->sole();

        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and(session('toast'))->toMatchArray([
                'type' => 'success',
                'title' => 'تم إنشاء الحساب',
                'message' => 'مرحباً بك في بوابة الذكاء الاصطناعي.',
            ]);

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid registration and clears the password fields', function () {
        Livewire::test(RegisterForm::class)
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->set('password_confirmation', 'different')
            ->call('register')
            ->assertHasErrors(['name', 'email', 'password'])
            ->assertSet('password', '')
            ->assertSet('password_confirmation', '');

        $this->assertGuest();
        expect(User::query()->count())->toBe(0);
    });

    it('rejects an email that is already taken', function () {
        User::factory()->create(['email' => 'livewire-taken@example.com']);

        Livewire::test(RegisterForm::class)
            ->set('name', 'مستخدم آخر')
            ->set('email', 'livewire-taken@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    });

    it('throttles livewire registration attempts using the registered limiter', function () {
        foreach (range(1, 5) as $index) {
            Livewire::test(RegisterForm::class)
                ->set('name', "User {$index}")
                ->set('email', "register{$index}@example.com")
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->call('register')
                ->assertHasNoErrors();
        }

        Livewire::test(RegisterForm::class)
            ->set('name', 'Blocked User')
            ->set('email', 'blocked@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['email']);

        expect(User::query()->where('email', 'blocked@example.com')->exists())->toBeFalse();
    });
});
