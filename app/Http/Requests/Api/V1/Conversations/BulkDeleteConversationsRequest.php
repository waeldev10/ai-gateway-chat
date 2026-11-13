<?php

namespace App\Http\Requests\Api\V1\Conversations;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string', 'distinct', 'ulid'],
        ];
    }
}
