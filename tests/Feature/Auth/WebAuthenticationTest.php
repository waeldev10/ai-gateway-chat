<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('web login', function () {
    it('renders the login screen', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('تسجيل الدخول');
    });

    it('authenticates a user and redirects to the new conversation', function () {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => strtoupper($user->email),
            'password' => 'password',
        ])
            ->assertRedirect(route('conversations.create'))
            ->assertSessionHas('toast.type', 'success');

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid credentials without authenticating', function () {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('keeps the email and does not keep the password after failed authentication', function () {
        $user = User::factory()->create();
        $submittedEmail = strtoupper($user->email);

        $this->post(route('login'), [
            'email' => $submittedEmail,
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors('email')
            ->assertSessionHasInput('email', $submittedEmail);

        expect(session()->getOldInput('password'))->toBeNull();
    });

    it('validates empty login input', function () {
        $this->post(route('login'), [
            'email' => '',
            'password' => '',
        ])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    });
});

describe('web registration', function () {
    it('renders the register screen', function () {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('إنشاء حساب');
    });

    it('registers and authenticates a new user', function () {
        $this->post(route('register'), [
            'name' => 'مستخدم جديد',
            'email' => 'web-new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertRedirect(route('conversations.create'))
            ->assertSessionHas('toast.type', 'success');

        $user = User::query()->where('email', 'web-new@example.com')->sole();

        expect(Hash::check('password', $user->password))->toBeTrue();

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid registration input', function () {
        $this->post(route('register'), [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertGuest();
        expect(User::query()->count())->toBe(0);
    });

    it('rejects a duplicate email', function () {
        User::factory()->create(['email' => 'web-taken@example.com']);

        $this->post(route('register'), [
            'name' => 'مستخدم آخر',
            'email' => 'web-taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});

describe('web logout', function () {
    it('logs the user out and redirects to login', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('toast.type', 'info');

        $this->assertGuest();
    });

    it('requires authentication to log out', function () {
        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });
});
