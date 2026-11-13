<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ConversationMessageService
{
    public function paginate(
        Conversation $conversation,
        int $limit = 25,
    ): CursorPaginator {
        $limit = min(max($limit, 1), 100);

        return $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();
    }
}
