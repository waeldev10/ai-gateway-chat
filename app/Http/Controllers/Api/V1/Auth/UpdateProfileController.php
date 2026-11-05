<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Services\Auth\UpdateProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateProfileController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateProfileRequest $request, UpdateProfileService $service): JsonResponse
    {
        $user = $service->update($request->user(), $request->safe()->only(['name', 'email']));

        return ApiResponse::resource(new UserResource($user), 'تم تحديث الملف الشخصي بنجاح.');
    }
}
