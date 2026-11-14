<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\RegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly RegisterService $registerService) {}

    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $this->registerService->registerAndAuthenticate(
            $request->safe()->only(['name', 'email', 'password'])
        );

        return redirect(route('conversations.create', absolute: false))
            ->with('toast', ['type' => 'success', 'title' => 'تم إنشاء الحساب', 'message' => 'مرحباً بك في بوابة الذكاء الاصطناعي.']);
    }
}
