<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RegisterService
{
    public function register(array $data): User
    {
        return User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }

    public function registerAndAuthenticate(array $data): User
    {
        $user = $this->register($data);

        Auth::login($user);

        session()->regenerate();

        return $user;
    }
}
