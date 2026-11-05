<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Services\Auth\LoginService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(LoginRequest $request, LoginService $service): JsonResponse
    {
        $user = $service->authenticate($request->safe()->only(['email', 'password']));

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken($request->validated('device_name', 'api-token'));

        return ApiResponse::resource(
            new AuthResource([
                'user' => $user,
                'token' => $token->plainTextToken,
            ]),
            'تم تسجيل الدخول بنجاح.',
        );
    }
}
