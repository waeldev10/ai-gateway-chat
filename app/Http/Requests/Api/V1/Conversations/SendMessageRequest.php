<?php

namespace App\Http\Requests\Api\V1\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:10000'],
            'persona_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
