<?php

namespace App\Http\Requests\Api\V1\Personas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StorePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Str::squish(trim($this->input('name')))]);
        }

        if (is_string($this->input('system_prompt'))) {
            $this->merge([
                'system_prompt' => trim(str_replace(["\r\n", "\r"], "\n", $this->input('system_prompt'))),
            ]);
        }
    }

    public function rules(): array
    {
        $maxName = (int) config('ai.personas.max_name_length', 100);
        $maxPrompt = (int) config('ai.personas.max_prompt_length', 8000);

        return [
            'name' => ['required', 'string', 'max:'.$maxName],
            'system_prompt' => ['required', 'string', 'max:'.$maxPrompt],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
