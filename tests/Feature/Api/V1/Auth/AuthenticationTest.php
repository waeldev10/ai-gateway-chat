<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('registration', function () {
    it('registers a user and issues a bearer token', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Example User',
            'email' => 'USER@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'token_type',
                ],
                'message',
            ])
            ->assertJsonPath('data.user.name', 'Example User')
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('message', 'تم تسجيل الحساب بنجاح.')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        expect($response->json('data.access_token'))
            ->toBeString()
            ->not->toBeEmpty();

        $user = User::query()->where('email', 'user@example.com')->sole();

        expect($user->name)->toBe('Example User')
            ->and(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(1)
            ->and($user->tokens()->sole()->name)->toBe('phpunit');

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    });

    it('validates required fields with Arabic messages', function () {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable()
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Validation Failed')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('detail', 'بيانات الطلب غير صحيحة.')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.name.0', 'الاسم مطلوب.')
            ->assertJsonPath('errors.email.0', 'البريد الإلكتروني مطلوب.')
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        expect($response->json('errors.password.0'))
            ->toContain('كلمة المرور')
            ->toMatch('/\p{Arabic}/u');
    });

    it('validates registration fields with Arabic messages', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => ['invalid'],
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password'])
            ->assertJsonPath('errors.name.0', 'يجب أن يكون الاسم نصًا.')
            ->assertJsonPath('errors.email.0', 'يجب أن يكون البريد الإلكتروني عنوان بريد إلكتروني صالحًا.')
            ->assertJsonPath('errors.password.0', 'يجب أن يحتوي كلمة المرور على 8 أحرف على الأقل.');

        $this->postJson('/api/v1/auth/register', [
            'name' => str_repeat('a', 256),
            'email' => 'user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'يجب ألا يزيد الاسم عن 255 حرفًا.');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Example User',
            'email' => 'user@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'حقل التأكيد غير مطابق للحقل كلمة المرور.');
    });

    it('rejects a duplicate email with an Arabic message', function () {
        User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Example User',
            'email' => 'USER@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'قيمة البريد الإلكتروني مستخدمة بالفعل.');

        expect(User::query()->count())->toBe(1);
    });

    it('rate limits registration attempts', function () {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('detail', 'عدد الطلبات كبير جدًا. يرجى المحاولة لاحقًا.')
            ->assertJsonPath('code', 'rate_limited');

        expect($response->json('retry_after'))
            ->toBeInt()
            ->toBeGreaterThan(0)
            ->toBe((int) $response->headers->get('Retry-After'));
    });
});

describe('login', function () {
    it('logs in and issues a bearer token', function () {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'USER@example.com',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'token_type',
                ],
                'message',
            ])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        expect($response->json('data.access_token'))
            ->toBeString()
            ->not->toBeEmpty()
            ->and($user->tokens()->count())->toBe(1)
            ->and($user->tokens()->sole()->name)->toBe('phpunit');
    });

    it('rejects invalid credentials without issuing a token', function () {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'بيانات الاعتماد غير صحيحة.');

        expect($user->tokens()->count())->toBe(0);
    });

    it('validates missing credentials with Arabic messages', function () {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('errors.email.0', 'البريد الإلكتروني مطلوب.');

        expect($response->json('errors.password.0'))
            ->toContain('كلمة المرور')
            ->toMatch('/\p{Arabic}/u');
    });

    it('rate limits repeated login attempts', function () {
        User::factory()->create(['email' => 'user@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'user@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('detail', 'عدد الطلبات كبير جدًا. يرجى المحاولة لاحقًا.')
            ->assertJsonPath('code', 'rate_limited');

        expect($response->json('retry_after'))
            ->toBeInt()
            ->toBeGreaterThan(0)
            ->toBe((int) $response->headers->get('Retry-After'));
    });

    it('normalizes the email when building the login rate limit key', function () {
        User::factory()->create(['email' => 'user@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $attempt % 2 === 0 ? ' USER@example.com ' : 'User@Example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    });

    it('does not share the login limit between different emails on the same IP', function () {
        User::factory()->create([
            'email' => 'first@example.com',
            'password' => 'password',
        ]);
        User::factory()->create([
            'email' => 'second@example.com',
            'password' => 'password',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'first@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'second@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'second@example.com');
    });

    it('does not error when the login email is not a string', function () {
        $this->postJson('/api/v1/auth/login', [
            'email' => ['invalid@example.com'],
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });
});

describe('logout', function () {
    it('revokes only the current token', function () {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current');
        $otherToken = $user->createToken('other');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم تسجيل الخروج بنجاح.');

        expect(PersonalAccessToken::query()->find($currentToken->accessToken->getKey()))->toBeNull()
            ->and(PersonalAccessToken::query()->find($otherToken->accessToken->getKey()))->not->toBeNull();

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    });
});

describe('logout all', function () {
    it('revokes every token belonging to the authenticated user', function () {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current');
        $user->createToken('other');

        $otherUser = User::factory()->create();
        $otherUserToken = $otherUser->createToken('other-user');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم إلغاء جميع رموز الوصول بنجاح.');

        expect($user->tokens()->count())->toBe(0)
            ->and($otherUser->tokens()->count())->toBe(1)
            ->and(PersonalAccessToken::query()->find($otherUserToken->accessToken->getKey()))->not->toBeNull();

        $this->app['auth']->forgetGuards();

        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withToken($otherUserToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    });
});

describe('authenticated user', function () {
    it('returns the authenticated user without sensitive fields', function () {
        $user = User::factory()->create([
            'name' => 'Example User',
            'email' => 'user@example.com',
        ]);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Example User')
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    });

    it('rejects an invalid bearer token', function () {
        $this->withToken('invalid-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});

describe('profile update', function () {
    it('updates profile fields and resets email verification when the email changes', function () {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'New Name',
                'email' => 'NEW@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('message', 'تم تحديث الملف الشخصي بنجاح.')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.password');

        $user->refresh();

        expect($user->name)->toBe('New Name')
            ->and($user->email)->toBe('new@example.com')
            ->and($user->email_verified_at)->toBeNull();
    });

    it('allows keeping the current email without clearing verification', function () {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'user@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'New Name',
                'email' => 'user@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'user@example.com');

        $user->refresh();

        expect($user->name)->toBe('New Name')
            ->and($user->email_verified_at)->not->toBeNull();
    });

    it('rejects an email already used by another user', function () {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/auth/profile', ['email' => 'TAKEN@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'قيمة البريد الإلكتروني مستخدمة بالفعل.');
    });

    it('validates profile updates with Arabic messages', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/auth/profile', [
                'name' => ['invalid'],
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'يجب أن يكون الاسم نصًا.')
            ->assertJsonPath('errors.email.0', 'يجب أن يكون البريد الإلكتروني عنوان بريد إلكتروني صالحًا.');
    });
});

describe('password change', function () {
    it('changes the password when the current password is correct', function () {
        $user = User::factory()->create(['password' => 'password']);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم تغيير كلمة المرور بنجاح.')
            ->assertJsonMissingPath('data.access_token');

        $user->refresh();

        expect(Hash::check('new-password', $user->password))->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeFalse();
    });

    it('rejects an invalid current password without changing the password', function () {
        $user = User::factory()->create(['password' => 'password']);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.current_password.0', 'كلمة المرور غير صحيحة.');

        $user->refresh();

        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and(Hash::check('new-password', $user->password))->toBeFalse();
    });

    it('validates password change input with Arabic messages', function () {
        $user = User::factory()->create(['password' => 'password']);
        $token = $user->createToken('test');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/v1/auth/password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password'])
            ->assertJsonPath('errors.current_password.0', 'كلمة المرور الحالية مطلوب.')
            ->assertJsonPath('errors.password.0', 'كلمة المرور مطلوب.');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'حقل التأكيد غير مطابق للحقل كلمة المرور.');
    });
});

describe('api rate limiting', function () {
    it('rate limits authenticated requests by user id', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        foreach (range(1, 60) as $attempt) {
            $this->getJson('/api/v1/auth/me')->assertOk();
        }

        $response = $this->getJson('/api/v1/auth/me')
            ->assertStatus(429)
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Too Many Requests')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('detail', 'عدد الطلبات كبير جدًا. يرجى المحاولة لاحقًا.')
            ->assertJsonPath('code', 'rate_limited');

        expect($response->json('retry_after'))
            ->toBeInt()
            ->toBeGreaterThan(0)
            ->toBe((int) $response->headers->get('Retry-After'));

        $this->app['auth']->forgetGuards();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $otherUser->id);
    });

});

describe('protected routes', function () {
    it('rejects unauthenticated requests', function (string $method, string $uri) {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Unauthenticated')
            ->assertJsonPath('status', 401)
            ->assertJsonPath('detail', 'Unauthenticated.')
            ->assertJsonPath('code', 'unauthenticated');
    })->with([
        ['POST', '/api/v1/auth/logout'],
        ['POST', '/api/v1/auth/logout-all'],
        ['GET', '/api/v1/auth/me'],
        ['PATCH', '/api/v1/auth/profile'],
        ['PUT', '/api/v1/auth/password'],
    ]);
});

describe('api error handling', function () {
    it('returns Arabic problem details for unknown endpoints and methods', function () {
        $this->getJson('/api/v1/unknown')
            ->assertNotFound()
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Not Found')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('detail', 'المسار المطلوب غير موجود.')
            ->assertJsonPath('code', 'not_found');

        $this->getJson('/api/v1/auth/login')
            ->assertStatus(405)
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'Method Not Allowed')
            ->assertJsonPath('status', 405)
            ->assertJsonPath('detail', 'طريقة HTTP غير مدعومة لهذا المسار.')
            ->assertJsonPath('code', 'method_not_allowed');
    });

    it('keeps web requests working', function () {
        $this->get('/')->assertOk();
    });
});
