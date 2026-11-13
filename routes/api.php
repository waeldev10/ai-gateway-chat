<?php

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\GetAuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\UpdateProfileController;
use App\Http\Controllers\Api\V1\Conversations\ConversationController;
use App\Http\Controllers\Api\V1\Conversations\MessageController;
use App\Http\Controllers\Api\V1\Personas\PersonaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    Route::prefix('auth')->name('auth.')->group(function (): void {

        Route::post('register', RegisterController::class)
            ->middleware('throttle:register')
            ->name('register');

        Route::post('login', LoginController::class)
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
            Route::post('logout', LogoutController::class)->name('logout');
            Route::post('logout-all', LogoutAllController::class)->name('logout-all');
            Route::get('me', GetAuthenticatedUserController::class)->name('me');
            Route::match(['patch', 'put'], 'profile', UpdateProfileController::class)->name('profile.update');
            Route::match(['put', 'patch'], 'password', ChangePasswordController::class)->name('password.update');
        });

    });

    Route::prefix('conversations')
        ->name('conversations.')
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->group(function (): void {

            Route::controller(ConversationController::class)->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('search', 'index')->name('search');
                Route::post('/', 'store')->name('store');
                Route::delete('/', 'destroyMany')->name('destroy-many');
                Route::get('{conversation}/messages', 'messages')->name('messages');
                Route::get('{conversation}', 'show')->name('show');
                Route::match(['patch', 'put'], '{conversation}', 'update')->name('update');
                Route::delete('{conversation}', 'destroy')->name('destroy');
                Route::post('{conversation}/pin', 'pin')->name('pin');
                Route::delete('{conversation}/pin', 'unpin')->name('unpin');
            });

            Route::controller(MessageController::class)->group(function (): void {
                Route::post('{conversation}/messages', 'store')->middleware('throttle:api-ai')->name('messages.store');
                Route::post('{conversation}/messages/continue', 'continueConversation')->middleware('throttle:api-ai')->name('messages.continue');
                Route::post('{conversation}/messages/{message}/regenerate', 'regenerate')
                    ->middleware('throttle:api-ai')
                    ->scopeBindings()
                    ->name('messages.regenerate');
                Route::match(['patch', 'put'], '{conversation}/messages/{message}', 'update')
                    ->scopeBindings()
                    ->name('messages.update');
            });

        });

    Route::prefix('personas')
        ->name('personas.')
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->controller(PersonaController::class)
        ->group(function (): void {

            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('{persona}', 'show')->name('show');
            Route::match(['put', 'patch'], '{persona}', 'update')->name('update');
            Route::delete('{persona}', 'destroy')->name('destroy');
            Route::post('{persona}/activate', 'activate')->name('activate');
            Route::post('{persona}/deactivate', 'deactivate')->name('deactivate');

        });

});
