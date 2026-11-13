<?php

namespace App\Http\Controllers\Api\V1\Personas;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Personas\StorePersonaRequest;
use App\Http\Requests\Api\V1\Personas\UpdatePersonaRequest;
use App\Http\Resources\Api\V1\Personas\AiPersonaResource;
use App\Models\AiPersona;
use App\Services\Persona\AiPersonaService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaController extends Controller
{
    public function __construct(private readonly AiPersonaService $personaService) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::collection(
            AiPersonaResource::collection($this->personaService->visibleFor($request->user())),
        );
    }

    public function show(AiPersona $persona): JsonResponse
    {
        $this->authorize('view', $persona);

        return ApiResponse::resource(new AiPersonaResource($persona));
    }

    public function store(StorePersonaRequest $request): JsonResponse
    {
        $this->authorize('create', AiPersona::class);

        $persona = $this->personaService->create($request->user(), $request->validated());

        return ApiResponse::resource(
            new AiPersonaResource($persona),
            'تم إنشاء الشخصية بنجاح.',
            201,
        );
    }

    public function update(UpdatePersonaRequest $request, AiPersona $persona): JsonResponse
    {
        $this->authorize('update', $persona);

        $persona = $this->personaService->update($request->user(), $persona, $request->validated());

        return ApiResponse::resource(
            new AiPersonaResource($persona),
            'تم تحديث الشخصية بنجاح.',
        );
    }

    public function destroy(Request $request, AiPersona $persona): JsonResponse
    {
        $this->authorize('delete', $persona);

        $this->personaService->delete($request->user(), $persona);

        return ApiResponse::action('تم حذف الشخصية بنجاح.');
    }

    public function activate(Request $request, AiPersona $persona): JsonResponse
    {
        $this->authorize('activate', $persona);

        $persona = $this->personaService->activate($request->user(), $persona);

        return ApiResponse::resource(
            new AiPersonaResource($persona),
            'تم تفعيل الشخصية بنجاح.',
        );
    }

    public function deactivate(Request $request, AiPersona $persona): JsonResponse
    {
        $this->authorize('deactivate', $persona);

        $persona = $this->personaService->deactivate($request->user(), $persona);

        return ApiResponse::resource(
            new AiPersonaResource($persona),
            'تم تعطيل الشخصية بنجاح.',
        );
    }
}
