<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** يتحقق من كلمة المرور الحالية ثم يحفظ كلمة المرور الجديدة للمستخدم. */
class ChangePasswordService
{
    public function change(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $user->password = $newPassword;
        $user->save();

        return true;
    }
}
