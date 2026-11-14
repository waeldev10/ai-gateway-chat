<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Personas\PersonaController;
use App\Http\Controllers\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('conversations.create')
        : redirect()->route('login');
})->name('home');

Route::prefix('auth')->middleware('guest')->group(function (): void {

    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');

});

Route::middleware('auth')->group(function () {
    Route::get('conversations/create', [ConversationController::class, 'create'])
        ->name('conversations.create');

    Route::get('profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::get('personas', [PersonaController::class, 'index'])
        ->name('personas.index');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

});
