<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function create(): View
    {
        return view('conversations.create');
    }

    public function search(): View
    {
        return view('conversations.search');
    }

    public function show(Conversation $conversation): View
    {
        $this->authorize('view', $conversation);

        return view('conversations.show', [
            'conversation' => $conversation,
        ]);
    }
}
