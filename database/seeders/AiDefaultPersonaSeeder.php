<?php

namespace Database\Seeders;

use App\Models\AiPersona;
use Illuminate\Database\Seeder;

class AiDefaultPersonaSeeder extends Seeder
{
    public const DEFAULT_NAME = AiPersona::SYSTEM_DEFAULT_NAME;

    public const DEFAULT_PROMPT = AiPersona::SYSTEM_DEFAULT_PROMPT;

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
