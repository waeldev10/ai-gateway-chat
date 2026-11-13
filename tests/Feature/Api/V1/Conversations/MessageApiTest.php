<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest as AiRequestData;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiException;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.test/v1',
        'model' => 'configured-model',
        'timeout' => 30,
    ]);
});

describe('sending messages', function () {
    it('persists the user message and the assistant reply', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Existing title']);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'Hello there'],
                ])))
                ->andReturn(new AiResponse(
                    'Hello, how can I help?',
                    'openai',
                    'gpt-test',
                    'stop',
                    AiUsage::fromTokens(12, 8),
                ));
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Hello there',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.user_message.content', 'Hello there')
            ->assertJsonPath('data.user_message.status', 'complete')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Hello, how can I help?')
            ->assertJsonPath('data.assistant_message.status', 'complete')
            ->assertJsonPath('message', 'تم إرسال الرسالة بنجاح.')
            ->assertJsonStructure([
                'data' => [
                    'user_message' => ['id', 'role', 'content', 'status', 'created_at', 'updated_at'],
                    'assistant_message' => ['id', 'role', 'content', 'status', 'created_at', 'updated_at'],
                ],
            ]);

        $messages = $conversation->messages()->orderBy('created_at')->orderBy('id')->get();

        expect($messages)->toHaveCount(2)
            ->and($messages[0]->role)->toBe(MessageRole::User)
            ->and($messages[1]->role)->toBe(MessageRole::Assistant)
            ->and($messages[1]->content)->toBe('Hello, how can I help?');

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Completed)
            ->and($aiRequest->source)->toBe(AiSource::Api)
            ->and($aiRequest->provider)->toBe('openai')
            ->and($aiRequest->model)->toBe('gpt-test')
            ->and($aiRequest->input_tokens)->toBe(12)
            ->and($aiRequest->output_tokens)->toBe(8)
            ->and($aiRequest->total_tokens)->toBe(20)
            ->and($aiRequest->message_id)->toBe($messages[1]->id)
            ->and($aiRequest->duration_ms)->toBeInt();
    });

    it('sends the conversation history to the ai provider', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'First answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'First question'],
                    ['role' => 'assistant', 'content' => 'First answer'],
                    ['role' => 'user', 'content' => 'Second question'],
                ])))
                ->andReturn(new AiResponse('Second answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Second question',
            ])
            ->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Second answer');
    });

    it('titles an untitled conversation from the first user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => null]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('A helpful reply', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Plan my trip to Japan',
            ])
            ->assertCreated();

        expect($conversation->refresh()->title)->toBe('Plan my trip to Japan');
    });

    it('keeps the user message when the ai provider fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Existing title']);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AiException::providerError('openai'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Will this be saved?',
            ])
            ->assertStatus(502)
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('title', 'AI Provider Error')
            ->assertJsonPath('status', 502)
            ->assertJsonPath('detail', 'تعذر الحصول على رد من مزود الذكاء الاصطناعي. يرجى المحاولة لاحقًا.')
            ->assertJsonPath('code', 'provider_failed');

        $messages = $conversation->messages()->get();

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->role)->toBe(MessageRole::User)
            ->and($messages->first()->content)->toBe('Will this be saved?');

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull()
            ->and($aiRequest->error_message)->not->toBeNull();
    });
});

describe('validation', function () {
    it('validates message content with Arabic messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.content.0', 'المحتوى مطلوب.');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['content' => ['invalid']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.content.0', 'يجب أن يكون المحتوى نصًا.');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => str_repeat('a', 10001),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.content.0', 'يجب ألا يزيد المحتوى عن 10000 حرفًا.');

        expect($conversation->messages()->count())->toBe(0);
    });
});

describe('authorization', function () {
    it('forbids sending messages to another user conversation', function () {
        $conversation = Conversation::factory()->create();

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Trying to inject a message',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect($conversation->messages()->count())->toBe(0)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('returns not found for an unknown conversation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/conversations/'.Str::ulid().'/messages', [
                'content' => 'Hello',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/conversations/'.Str::ulid().'/messages', [
            'content' => 'Hello',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
