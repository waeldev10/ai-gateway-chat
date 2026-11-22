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

it('uses the active persona automatically for a new message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'You are a pirate. Answer in pirate language.',
        'is_active' => true,
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
        ])
        ->assertCreated()
        ->assertJsonPath('data.assistant_message.content', 'Ahoy!');
});

it('uses the active persona automatically when editing a message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Answer as a poet.',
        'is_active' => true,
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
        ])
        ->assertOk();
});

it('uses the active persona automatically when regenerating a reply', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $persona = AiPersona::factory()->for($user)->create([
        'system_prompt' => 'Answer as a scientist.',
        'is_active' => true,
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
        ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
        ->assertOk()
        ->assertJsonPath('data.content', 'Rayleigh scattering.');
});

it('uses the system default persona when no custom persona is active', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    AiPersona::factory()->for($user)->inactive()->create([
        'system_prompt' => 'Inactive instructions.',
    ]);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                ['role' => 'user', 'content' => 'Hello'],
            ])))
            ->andReturn(new AiResponse('Default reply.', 'openai', 'gpt-test'));
    });

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello',
        ])
        ->assertCreated()
        ->assertJsonPath('data.assistant_message.content', 'Default reply.');
});
