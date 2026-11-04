<?php

namespace Database\Seeders;

use App\Models\AiPersona;
use Illuminate\Database\Seeder;

class AiDefaultPersonaSeeder extends Seeder
{
    public const DEFAULT_NAME = 'Default Assistant';

    public const DEFAULT_PROMPT = 'You are a helpful AI assistant. Answer clearly and concisely, and match the user language.';

    public function run(): void
    {
        AiPersona::query()->firstOrCreate(
            [
                'user_id' => null,
                'is_default' => true,
            ],
            [
                'name' => self::DEFAULT_NAME,
                'system_prompt' => self::DEFAULT_PROMPT,
                'is_active' => true,
            ],
        );
    }
}
