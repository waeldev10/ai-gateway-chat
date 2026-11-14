<?php

use App\Livewire\Persona\PersonaSettings;
use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('requires authentication to view persona settings', function () {
    $this->get(route('personas.index'))->assertRedirect(route('login'));
});

it('renders the system default and only the authenticated user personas', function () {
    $user = User::factory()->create();
    $persona = AiPersona::factory()->for($user)->create(['name' => 'My Persona']);
    AiPersona::factory()->for(User::factory())->create(['name' => 'Foreign Persona']);

    $this->actingAs($user);

    $this->get(route('personas.index'))
        ->assertOk()
        ->assertSeeLivewire('persona.persona-settings')
        ->assertSee(AiPersona::SYSTEM_DEFAULT_NAME);

    Livewire::test(PersonaSettings::class)
        ->assertSee(AiPersona::SYSTEM_DEFAULT_NAME)
        ->assertSee($persona->name)
        ->assertDontSee('Foreign Persona');
});

it('creates a persona for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->set('name', '  Code   Reviewer ')
        ->set('system_prompt', "Review code carefully.\r\nExplain tradeoffs.")
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSet('system_prompt', '')
        ->assertDispatched('toast');

    $persona = AiPersona::query()->where('user_id', $user->id)->sole();

    expect($persona->name)->toBe('Code Reviewer')
        ->and($persona->system_prompt)->toBe("Review code carefully.\nExplain tradeoffs.")
        ->and($persona->is_active)->toBeTrue()
        ->and($persona->is_default)->toBeFalse();
});

it('validates persona fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->set('name', '')
        ->set('system_prompt', '')
        ->call('create')
        ->assertHasErrors(['name', 'system_prompt']);

    expect(AiPersona::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('enforces the custom persona limit', function () {
    config()->set('ai.personas.max_custom', 2);

    $user = User::factory()->create();
    AiPersona::factory()->count(2)->for($user)->create();

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->set('name', 'One too many')
        ->set('system_prompt', 'Be brief.')
        ->call('create')
        ->assertHasErrors(['name']);

    expect($user->aiPersonas()->count())->toBe(2);
});

it('hides the create form once the limit is reached', function () {
    config()->set('ai.personas.max_custom', 1);

    $user = User::factory()->create();
    AiPersona::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->assertSet('canCreate', false)
        ->assertSee('لقد وصلت إلى الحد الأقصى');
});

it('updates an owned persona', function () {
    $user = User::factory()->create();
    $persona = AiPersona::factory()->for($user)->create([
        'name' => 'Old Persona',
        'system_prompt' => 'Old instructions.',
    ]);

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->call('startEdit', $persona->id)
        ->assertSet('editingId', $persona->id)
        ->set('editingName', 'Updated Persona')
        ->set('editingSystemPrompt', 'Updated instructions.')
        ->call('update')
        ->assertHasNoErrors()
        ->assertSet('editingId', null)
        ->assertDispatched('toast');

    expect($persona->refresh()->name)->toBe('Updated Persona')
        ->and($persona->system_prompt)->toBe('Updated instructions.');
});

it('does not allow editing another user persona', function () {
    $user = User::factory()->create();
    $foreign = AiPersona::factory()->for(User::factory())->create(['name' => 'Foreign Persona']);

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->call('startEdit', $foreign->id);

    expect($foreign->refresh()->name)->toBe('Foreign Persona');
});

it('toggles an owned persona independently', function () {
    $user = User::factory()->create();
    $persona = AiPersona::factory()->for($user)->create(['is_active' => true]);

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->call('toggle', $persona->id)
        ->assertDispatched('toast');

    expect($persona->refresh()->is_active)->toBeFalse();

    Livewire::test(PersonaSettings::class)
        ->call('toggle', $persona->id)
        ->assertDispatched('toast');

    expect($persona->refresh()->is_active)->toBeTrue();
});

it('requires a second call before deleting a persona', function () {
    $user = User::factory()->create();
    $persona = AiPersona::factory()->for($user)->create();

    $this->actingAs($user);

    $component = Livewire::test(PersonaSettings::class)
        ->call('remove', $persona->id)
        ->assertSet('confirmingId', $persona->id);

    expect(AiPersona::query()->whereKey($persona->id)->exists())->toBeTrue();

    $component->call('remove', $persona->id)
        ->assertSet('confirmingId', null)
        ->assertDispatched('toast');

    expect(AiPersona::query()->whereKey($persona->id)->exists())->toBeFalse();
});

it('does not expose or delete the system default', function () {
    $user = User::factory()->create();
    $system = AiPersona::factory()->systemDefault()->create();

    $this->actingAs($user);

    Livewire::test(PersonaSettings::class)
        ->assertSee(AiPersona::SYSTEM_DEFAULT_NAME)
        ->assertDontSee('تأكيد الحذف')
        ->call('remove', $system->id)
        ->call('remove', $system->id);

    expect(AiPersona::query()->whereKey($system->id)->exists())->toBeTrue();
});
