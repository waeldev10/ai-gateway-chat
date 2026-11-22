<?php

namespace App\Services\Auth;

use App\Models\User;

/** يحذف جميع رموز Sanctum المرتبطة بالمستخدم لتسجيل خروجه من الأجهزة. */
class LogoutAllService
{
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
