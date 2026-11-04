<?php

use App\Models\AiPersona;
use Database\Seeders\AiDefaultPersonaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a default system persona when none exists', function () {
    $this->seed(AiDefaultPersonaSeeder::class);

    $persona = AiPersona::query()
        ->whereNull('user_id')
        ->where('is_default', true)
        ->sole();

    expect($persona->name)->toBe(AiDefaultPersonaSeeder::DEFAULT_NAME)
        ->and($persona->system_prompt)->toBe(AiDefaultPersonaSeeder::DEFAULT_PROMPT)
        ->and($persona->is_active)->toBeTrue()
        ->and($persona->is_default)->toBeTrue()
        ->and($persona->user_id)->toBeNull()
        ->and(AiPersona::query()->count())->toBe(1);
});

it('is idempotent across repeated runs', function () {
    $this->seed(AiDefaultPersonaSeeder::class);

    $first = AiPersona::query()->sole();

    $this->seed(AiDefaultPersonaSeeder::class);

    $second = AiPersona::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->name)->toBe($first->name)
        ->and($second->system_prompt)->toBe($first->system_prompt)
        ->and(AiPersona::query()->where('is_default', true)->count())->toBe(1);
});

it('does not overwrite an existing customized default', function () {
    $persona = AiPersona::create([
        'user_id' => null,
        'name' => 'Custom Assistant',
        'system_prompt' => 'Custom prompt.',
        'is_active' => false,
        'is_default' => true,
    ]);

    $this->seed(AiDefaultPersonaSeeder::class);

    $persona->refresh();

    expect($persona->name)->toBe('Custom Assistant')
        ->and($persona->system_prompt)->toBe('Custom prompt.')
        ->and($persona->is_active)->toBeFalse()
        ->and($persona->is_default)->toBeTrue()
        ->and(AiPersona::query()->count())->toBe(1);
});

it('does not normalize duplicate flagged defaults', function () {
    $first = AiPersona::create([
        'user_id' => null,
        'name' => 'First Default',
        'system_prompt' => 'First prompt.',
        'is_active' => true,
        'is_default' => true,
    ]);

    $second = AiPersona::create([
        'user_id' => null,
        'name' => 'Second Default',
        'system_prompt' => 'Second prompt.',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->seed(AiDefaultPersonaSeeder::class);

    expect($first->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(AiPersona::query()->count())->toBe(2);
});

it('does not promote a legacy active system persona', function () {
    $legacy = AiPersona::create([
        'user_id' => null,
        'name' => 'Legacy Active',
        'system_prompt' => 'Legacy prompt.',
        'is_active' => true,
        'is_default' => false,
    ]);

    $this->seed(AiDefaultPersonaSeeder::class);

    expect($legacy->fresh()->is_default)->toBeFalse()
        ->and(AiPersona::query()->where('is_default', true)->count())->toBe(1)
        ->and(AiPersona::query()->count())->toBe(2);
});
