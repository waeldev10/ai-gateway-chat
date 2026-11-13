<?php

namespace App\Policies;

use App\Models\AiPersona;
use App\Models\User;

class AiPersonaPolicy
{
    public function view(User $user, AiPersona $persona): bool
    {
        return $persona->isSystemDefault() || $persona->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AiPersona $persona): bool
    {
        return $this->ownsCustomPersona($user, $persona);
    }

    public function delete(User $user, AiPersona $persona): bool
    {
        return $this->ownsCustomPersona($user, $persona);
    }

    public function activate(User $user, AiPersona $persona): bool
    {
        return $this->ownsCustomPersona($user, $persona);
    }

    public function deactivate(User $user, AiPersona $persona): bool
    {
        return $this->ownsCustomPersona($user, $persona);
    }

    private function ownsCustomPersona(User $user, AiPersona $persona): bool
    {
        return ! $persona->isSystemDefault() && $persona->isOwnedBy($user);
    }
}
