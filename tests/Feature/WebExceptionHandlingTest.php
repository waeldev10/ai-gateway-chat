<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->group(function (): void {
        Route::post('/testing/web-validation', function (Request $request): void {
            $request->validate([
                'name' => ['required', 'string'],
            ]);
        });

        Route::get('/testing/login', fn (): string => 'login')->name('login');

        Route::middleware('auth')->get('/testing/web-protected', fn (): string => 'protected');
    });

    Route::getRoutes()->refreshNameLookups();

    AuthenticationException::redirectUsing(fn (): string => route('login'));
});

afterEach(function () {
    AuthenticationException::redirectUsing(fn () => null);
});

it('keeps web validation errors in the session instead of returning problem details', function () {
    $this->from('/testing/web-form')
        ->post('/testing/web-validation', ['name' => ''])
        ->assertRedirect('/testing/web-form')
        ->assertSessionHasErrors(['name']);

    expect(session('errors')->first('name'))
        ->toBe('الاسم مطلوب.');
});

it('keeps web JSON validation errors in Laravel format', function () {
    $this->postJson('/testing/web-validation', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'الاسم مطلوب.')
        ->assertJsonValidationErrors(['name'])
        ->assertJsonMissingPath('type')
        ->assertJsonMissingPath('code');
});

it('redirects unauthenticated web requests to the login route', function () {
    $this->get('/testing/web-protected')
        ->assertRedirect(route('login'));
});
