<?php

namespace Database\Factories;

use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPersona>
 */
class AiPersonaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'system_prompt' => fake()->paragraph(),
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function systemDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
            'name' => AiPersona::SYSTEM_DEFAULT_NAME,
            'system_prompt' => AiPersona::SYSTEM_DEFAULT_PROMPT,
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
