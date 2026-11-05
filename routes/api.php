<?php

use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\GetAuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\UpdateProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function (): void {
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
