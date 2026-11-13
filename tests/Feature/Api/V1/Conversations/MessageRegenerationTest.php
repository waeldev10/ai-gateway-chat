<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest as AiRequestData;
use App\AI\DTOs\AiResponse;
use App\Enums\AiRequestStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiException;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageContextService;
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

describe('regenerating assistant replies', function () {
    it('replaces an assistant message in place and preserves later messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(6),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Failed answer',
            'status' => MessageStatus::Failed,
            'created_at' => now()->subMinutes(5),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Stale answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(4),
        ]);
        $laterUser = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Later question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(3),
        ]);
        $laterAssistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Later answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'First question'],
                ])))
                ->andReturn(new AiResponse('Fresh answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Fresh answer')
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('message', 'تم إعادة توليد الرد بنجاح.');

        expect($conversation->messages()->count())->toBe(5)
            ->and($target->refresh()->content)->toBe('Fresh answer')
            ->and($laterUser->refresh()->content)->toBe('Later question')
            ->and($laterAssistant->refresh()->content)->toBe('Later answer')
            ->and($laterAssistant->refresh()->status)->toBe(MessageStatus::Complete);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Completed)
            ->and($aiRequest->message_id)->toBe($target->id);
    });

    it('regenerates the assistant following a user target', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $userMessage = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Old answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'Question'],
                ])))
                ->andReturn(new AiResponse('New answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$userMessage->id}/regenerate")
            ->assertOk()
            ->assertJsonPath('data.id', $assistant->id)
            ->assertJsonPath('data.content', 'New answer');

        expect($conversation->messages()->count())->toBe(2)
            ->and($userMessage->refresh()->content)->toBe('Question')
            ->and($assistant->refresh()->content)->toBe('New answer');
    });

    it('creates an assistant when regenerating the latest user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $userMessage = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Unanswered question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Created answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$userMessage->id}/regenerate")
            ->assertOk()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Created answer');

        expect($conversation->messages()->count())->toBe(2);

        $assistant = $conversation->messages()
            ->where('role', MessageRole::Assistant)
            ->sole();

        expect($assistant->content)->toBe('Created answer')
            ->and($assistant->status)->toBe(MessageStatus::Complete);
    });

    it('leaves every message unchanged when regeneration fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $userMessage = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(3),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);
        $later = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Later question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AiException::providerError('openai'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_failed');

        expect($conversation->messages()->count())->toBe(3)
            ->and($target->refresh()->content)->toBe('Original answer')
            ->and($target->refresh()->status)->toBe(MessageStatus::Complete)
            ->and($userMessage->refresh()->content)->toBe('Question')
            ->and($later->refresh()->content)->toBe('Later question');

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });

    it('rejects regeneration when no completed context exists', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Lonely answer',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$assistant->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا توجد رسائل مكتملة كافية لإعادة توليد الرد.');

        expect(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rejects regenerating a user message followed by another user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $first = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Second',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$first->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا يمكن إعادة توليد الرد لأن الرسالة متبوعة برسالة أخرى.');

        expect($conversation->messages()->count())->toBe(2);
    });

    it('validates the next message before retrieving historical context', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $first = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        $second = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Second',
            'status' => MessageStatus::Complete,
        ]);

        $this->partialMock(MessageContextService::class, function (MockInterface $mock) use ($second): void {
            $mock->shouldReceive('nextMessageAfter')->once()->andReturn($second);
            $mock->shouldNotReceive('completedHistory');
        });

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$first->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا يمكن إعادة توليد الرد لأن الرسالة متبوعة برسالة أخرى.');

        expect($conversation->messages()->count())->toBe(2)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });
});

describe('regeneration authorization and lookup', function () {
    it('forbids regenerating in another user conversation', function () {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$message->id}/regenerate")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });

    it('returns not found when the message belongs to another conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $otherConversation = Conversation::factory()->for($user)->create();
        $message = Message::factory()->for($otherConversation)->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$message->id}/regenerate")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('returns not found for an unknown message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/".Str::ulid().'/regenerate')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/conversations/'.Str::ulid().'/messages/'.Str::ulid().'/regenerate')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
