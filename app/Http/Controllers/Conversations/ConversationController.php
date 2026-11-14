<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function create(): View
    {
        return view('conversations.create');
    }
}
