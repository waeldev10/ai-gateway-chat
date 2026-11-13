<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Determine whether the user can view the conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    /**
     * Determine whether the user can update the conversation.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    /**
     * Determine whether the user can delete the conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    /**
     * Determine whether the user can send messages to the conversation.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, Conversation $conversation): bool
    {
        return $user->getKey() === $conversation->user_id;
    }
}
