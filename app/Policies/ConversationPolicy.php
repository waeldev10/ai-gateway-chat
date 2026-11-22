<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, Conversation $conversation): bool
    {
        return $user->getKey() === $conversation->user_id;
    }
}
