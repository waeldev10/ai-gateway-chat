<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** ينهي جلسة الويب الحالية أو يحذف رمز Sanctum الحالي للمستخدم. */
class LogoutService
{
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function logoutWeb(User $user): void
    {
        $this->logout($user);

        Auth::logout();

        session()->invalidate();

        session()->regenerateToken();
    }
}
