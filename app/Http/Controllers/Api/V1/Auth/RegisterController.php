<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Services\Auth\RegisterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RegisterRequest $request, RegisterService $service): JsonResponse
    {
        $user = $service->register($request->safe()->only(['name', 'email', 'password']));
        $token = $user->createToken($request->validated('device_name', 'api-token'));

        return ApiResponse::resource(
            new AuthResource([
                'user' => $user,
                'token' => $token->plainTextToken,
            ]),
            'تم تسجيل الحساب بنجاح.',
            201,
        );
    }
}
