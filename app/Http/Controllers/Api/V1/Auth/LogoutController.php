<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LogoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, LogoutService $service): JsonResponse
    {
        $service->logout($request->user());

        return ApiResponse::action('تم تسجيل الخروج بنجاح.');
    }
}
