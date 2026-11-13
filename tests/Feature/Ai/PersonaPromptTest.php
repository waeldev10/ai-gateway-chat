<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest as AiRequestData;
use App\AI\DTOs\AiResponse;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\Matcher\Closure;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.test/v1',
        'model' => 'configured-model',
        'timeout' => 30,
    ]);
});

function expectRequestWithPersona(string $systemPrompt): Closure
{
    return Mockery::on(fn (AiRequestData $request): bool => $request->messages[0] === [
        'role' => 'system',
        'content' => $systemPrompt,
    ]);
}

it('uses the explicitly selected persona for a new message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'You are a pirate. Answer in pirate language.',
    ]);

    $this->mock(AiService::class, function (MockInterface $mock) use ($persona): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(expectRequestWithPersona($persona->system_prompt))
            ->andReturn(new AiResponse('Ahoy!', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello',
            'persona_id' => $persona->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.assistant_message.content', 'Ahoy!');
});

it('falls back to the most recently updated active persona', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Older prompt.',
        'updated_at' => now()->subHour(),
    ]);
    $newestActive = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Newest active prompt.',
        'updated_at' => now()->subMinute(),
    ]);
    AiPersona::factory()->for($user)->inactive()->create([
        'system_prompt' => 'Newest inactive prompt.',
        'updated_at' => now(),
    ]);

    $this->mock(AiService::class, function (MockInterface $mock) use ($newestActive): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(expectRequestWithPersona($newestActive->system_prompt))
            ->andReturn(new AiResponse('Response', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello',
        ])
        ->assertCreated();
});

it('rejects an inactive selected persona', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->inactive()->create();

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('generate');
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello',
            'persona_id' => $persona->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.persona_id.0', 'الشخصية المحددة غير متاحة.');

    expect($conversation->messages()->count())->toBe(0);
});

it('rejects another user selected persona', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->create();

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('generate');
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello',
            'persona_id' => $persona->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.persona_id.0', 'الشخصية المحددة غير متاحة.');
});

it('uses the selected persona when continuing a conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Continue like a historian.',
    ]);

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User,
        'content' => 'Tell me about Rome.',
        'status' => MessageStatus::Complete,
    ]);

    $this->mock(AiService::class, function (MockInterface $mock) use ($persona): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(expectRequestWithPersona($persona->system_prompt))
            ->andReturn(new AiResponse('Rome was founded in 753 BC.', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue", [
            'persona_id' => $persona->id,
        ])
        ->assertOk();
});

it('uses the selected persona when editing a message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Answer as a poet.',
    ]);
    $target = Message::factory()->for($conversation)->create([
        'role' => MessageRole::User,
        'content' => 'Original question',
        'status' => MessageStatus::Complete,
    ]);

    $this->mock(AiService::class, function (MockInterface $mock) use ($persona): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(expectRequestWithPersona($persona->system_prompt))
            ->andReturn(new AiResponse('A poetic answer.', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
            'content' => 'Edited question',
            'persona_id' => $persona->id,
        ])
        ->assertOk();
});

it('uses the selected persona when regenerating a reply', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Answer as a scientist.',
    ]);

    Message::factory()->for($conversation)->create([
        'role' => MessageRole::User,
        'content' => 'Why is the sky blue?',
        'status' => MessageStatus::Complete,
        'created_at' => now()->subMinute(),
    ]);
    $target = Message::factory()->for($conversation)->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->mock(AiService::class, function (MockInterface $mock) use ($persona): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(expectRequestWithPersona($persona->system_prompt))
            ->andReturn(new AiResponse('Rayleigh scattering.', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate", [
            'persona_id' => $persona->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.content', 'Rayleigh scattering.');
});
