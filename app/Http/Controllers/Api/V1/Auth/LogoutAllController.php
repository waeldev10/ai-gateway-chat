<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LogoutAllService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutAllController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, LogoutAllService $service): JsonResponse
    {
        $service->logoutAll($request->user());

        return ApiResponse::action('تم إلغاء جميع رموز الوصول بنجاح.');
    }
}
