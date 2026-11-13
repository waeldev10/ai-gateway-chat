<?php

namespace App\Http\Resources\Api\V1\Personas;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiPersonaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'system_prompt' => $this->system_prompt,
            'is_active' => $this->is_active,
            'is_system' => $this->isSystemDefault(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
