<?php

namespace App\Services\Auth;

use App\Models\User;

class UpdateProfileService
{
    /**
     * @param  array{name?: string, email?: string}  $data
     */
    public function update(User $user, array $data): User
    {
        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty()) {
            $user->save();
        }

        return $user;
    }
}
