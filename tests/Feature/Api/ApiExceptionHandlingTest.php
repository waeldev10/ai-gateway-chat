<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->group(function (): void {
        Route::get('/api/v1/testing/forbidden', function (): never {
            throw new AuthorizationException('Sensitive authorization detail.');
        });

        Route::get('/api/v1/testing/missing-model', function (): never {
            throw (new ModelNotFoundException)->setModel(User::class, ['missing-id']);
        });

        Route::get('/api/v1/testing/server-error', function (): never {
            throw new RuntimeException('SQLSTATE[HY000] credentials leaked.');
        });
    });
});

it('renders forbidden API exceptions without exposing authorization details', function () {
    $response = $this->getJson('/api/v1/testing/forbidden')
        ->assertForbidden()
        ->assertJsonPath('type', 'about:blank')
        ->assertJsonPath('title', 'Forbidden')
        ->assertJsonPath('status', 403)
        ->assertJsonPath('code', 'forbidden');

    expect($response->getContent())
        ->not->toContain('Sensitive authorization detail.');
});

it('renders missing API models as not found without exposing model details', function () {
    $response = $this->getJson('/api/v1/testing/missing-model')
        ->assertNotFound()
        ->assertJsonPath('type', 'about:blank')
        ->assertJsonPath('title', 'Not Found')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'not_found');

    expect($response->getContent())
        ->not->toContain('No query results')
        ->not->toContain('missing-id');
});

it('renders unexpected API exceptions as a safe server error', function () {
    $response = $this->getJson('/api/v1/testing/server-error')
        ->assertStatus(500)
        ->assertJsonPath('type', 'about:blank')
        ->assertJsonPath('title', 'Server Error')
        ->assertJsonPath('status', 500)
        ->assertJsonPath('detail', 'حدث خطأ غير متوقع. يرجى المحاولة لاحقًا.')
        ->assertJsonPath('code', 'server_error')
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('exception');

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('credentials leaked')
        ->not->toContain('RuntimeException');
});

it('renders the API root as problem details when no route exists', function () {
    $this->getJson('/api')
        ->assertNotFound()
        ->assertJsonPath('type', 'about:blank')
        ->assertJsonPath('title', 'Not Found')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'not_found');
});
