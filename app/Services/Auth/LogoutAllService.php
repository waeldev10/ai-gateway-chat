<?php

namespace App\Services\Auth;

use App\Models\User;

class LogoutAllService
{
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
