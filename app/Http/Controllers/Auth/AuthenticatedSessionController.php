<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\LoginService;
use App\Services\Auth\LogoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, LoginService $loginService): RedirectResponse
    {
        $loginService->login(
            $request->safe()->only(['email', 'password']),
            $request->boolean('remember'),
        );

        return redirect()
            ->intended(route('conversations.create', absolute: false))
            ->with('toast', [
                'type' => 'success',
                'title' => 'مرحباً بعودتك',
                'message' => 'تم تسجيل الدخول بنجاح.',
            ]);
    }

    public function destroy(Request $request, LogoutService $logoutService): RedirectResponse
    {
        $logoutService->logoutWeb($request->user());

        return redirect()->route('login')
            ->with('toast', [
                'type' => 'info',
                'title' => 'تم تسجيل الخروج',
                'message' => 'نراك قريباً.',
            ]);
    }
}
