<?php

use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('listing personas', function () {
    it('lists the system default and the authenticated user personas', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->for($user)->create(['name' => 'My Persona']);
        AiPersona::factory()->for(User::factory())->create(['name' => 'Foreign Persona']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/personas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_system', true)
            ->assertJsonPath('data.0.name', AiPersona::SYSTEM_DEFAULT_NAME)
            ->assertJsonFragment([
                'id' => $persona->id,
                'name' => 'My Persona',
                'is_system' => false,
            ])
            ->assertJsonMissing(['name' => 'Foreign Persona']);
    });

    it('shows a visible persona', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/personas/{$persona->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $persona->id)
            ->assertJsonPath('data.is_system', false);
    });

    it('forbids viewing another user persona', function () {
        $persona = AiPersona::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/personas/{$persona->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });
});

describe('creating personas', function () {
    it('creates a custom persona for the authenticated user', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/personas', [
                'name' => '  Code   Reviewer ',
                'system_prompt' => "Review code carefully.\r\nExplain tradeoffs.",
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Code Reviewer')
            ->assertJsonPath('data.system_prompt', "Review code carefully.\nExplain tradeoffs.")
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('message', 'تم إنشاء الشخصية بنجاح.');

        $persona = AiPersona::query()->sole();

        expect($persona->user_id)->toBe($user->id)
            ->and($persona->is_default)->toBeFalse();
    });

    it('enforces the custom persona limit', function () {
        config()->set('ai.personas.max_custom', 2);

        $user = User::factory()->create();
        AiPersona::factory()->count(2)->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/personas', [
                'name' => 'One too many',
                'system_prompt' => 'Be brief.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.name.0', 'لا يمكن إنشاء أكثر من 2 شخصيات مخصصة.');

        expect($user->aiPersonas()->count())->toBe(2);
    });

    it('validates persona fields', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/personas', [
                'name' => '',
                'system_prompt' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'system_prompt']);
    });
});

describe('updating and deleting personas', function () {
    it('updates an owned persona', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/personas/{$persona->id}", [
                'name' => 'Updated Persona',
                'system_prompt' => 'Updated instructions.',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Persona')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', 'تم تحديث الشخصية بنجاح.');

        expect($persona->refresh()->name)->toBe('Updated Persona')
            ->and($persona->is_active)->toBeFalse();
    });

    it('forbids updating the system default', function () {
        $user = User::factory()->create();
        $system = AiPersona::factory()->systemDefault()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/personas/{$system->id}", [
                'name' => 'Changed',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect($system->refresh()->name)->toBe(AiPersona::SYSTEM_DEFAULT_NAME);
    });

    it('forbids updating another user persona', function () {
        $persona = AiPersona::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->patchJson("/api/v1/personas/{$persona->id}", [
                'name' => 'Hijacked',
            ])
            ->assertForbidden();
    });

    it('deletes an owned persona', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/personas/{$persona->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف الشخصية بنجاح.');

        expect(AiPersona::query()->whereKey($persona->id)->exists())->toBeFalse();
    });

    it('forbids deleting the system default', function () {
        $user = User::factory()->create();
        $system = AiPersona::factory()->systemDefault()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/personas/{$system->id}")
            ->assertForbidden();

        expect(AiPersona::query()->whereKey($system->id)->exists())->toBeTrue();
    });
});

describe('activating and deactivating personas', function () {
    it('activates and deactivates an owned persona', function () {
        $user = User::factory()->create();
        $persona = AiPersona::factory()->for($user)->inactive()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/personas/{$persona->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('message', 'تم تفعيل الشخصية بنجاح.');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/personas/{$persona->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', 'تم تعطيل الشخصية بنجاح.');

        expect($persona->refresh()->is_active)->toBeFalse();
    });

    it('forbids changing the system default status', function () {
        $user = User::factory()->create();
        $system = AiPersona::factory()->systemDefault()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/personas/{$system->id}/deactivate")
            ->assertForbidden();

        expect($system->refresh()->is_active)->toBeTrue();
    });
});

it('requires authentication for persona endpoints', function () {
    $this->getJson('/api/v1/personas')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->postJson('/api/v1/personas', [
        'name' => 'Persona',
        'system_prompt' => 'Instructions.',
    ])
        ->assertUnauthorized();
});
