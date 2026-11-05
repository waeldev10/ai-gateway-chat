<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Services\Auth\ChangePasswordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ChangePasswordController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ChangePasswordRequest $request, ChangePasswordService $service): JsonResponse
    {
        $changed = $service->change(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        if (! $changed) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.password')],
            ]);
        }

        return ApiResponse::action('تم تغيير كلمة المرور بنجاح.');
    }
}
