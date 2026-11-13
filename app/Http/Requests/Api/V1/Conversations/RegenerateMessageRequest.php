<?php

namespace App\Http\Requests\Api\V1\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'persona_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
