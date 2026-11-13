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

describe('editing user messages', function () {
    it('edits the user message and regenerates its immediate assistant reply', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $priorUser = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Prior question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(6),
        ]);
        $priorAssistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Prior answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(5),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(4),
        ]);
        $targetAssistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(3),
        ]);
        $laterUser = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Later question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);
        $laterAssistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Later answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'Prior question'],
                    ['role' => 'assistant', 'content' => 'Prior answer'],
                    ['role' => 'user', 'content' => 'Edited question'],
                ])))
                ->andReturn(new AiResponse('Edited answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_message.id', $target->id)
            ->assertJsonPath('data.user_message.content', 'Edited question')
            ->assertJsonPath('data.user_message.status', 'complete')
            ->assertJsonPath('data.assistant_message.id', $targetAssistant->id)
            ->assertJsonPath('data.assistant_message.content', 'Edited answer')
            ->assertJsonPath('data.assistant_message.status', 'complete')
            ->assertJsonPath('message', 'تم تعديل الرسالة بنجاح.');

        expect($conversation->messages()->count())->toBe(6)
            ->and($target->refresh()->content)->toBe('Edited question')
            ->and($targetAssistant->refresh()->content)->toBe('Edited answer')
            ->and($priorUser->refresh()->content)->toBe('Prior question')
            ->and($priorAssistant->refresh()->content)->toBe('Prior answer')
            ->and($laterUser->refresh()->content)->toBe('Later question')
            ->and($laterAssistant->refresh()->content)->toBe('Later answer');

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Completed)
            ->and($aiRequest->message_id)->toBe($targetAssistant->id);
    });

    it('creates an assistant reply when editing the latest user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'Edited question'],
                ])))
                ->andReturn(new AiResponse('Created answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_message.content', 'Edited question')
            ->assertJsonPath('data.assistant_message.content', 'Created answer');

        expect($conversation->messages()->count())->toBe(2);

        $assistant = $conversation->messages()
            ->where('role', MessageRole::Assistant)
            ->sole();

        expect($assistant->content)->toBe('Created answer')
            ->and($assistant->status)->toBe(MessageStatus::Complete);
    });

    it('marks the edited user message complete after a successful edit', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Failed,
            'created_at' => now()->subMinute(),
        ]);
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Failed,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Fresh answer', 'openai', 'gpt-test'));
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_message.content', 'Edited question')
            ->assertJsonPath('data.user_message.status', 'complete')
            ->assertJsonPath('data.assistant_message.content', 'Fresh answer')
            ->assertJsonPath('data.assistant_message.status', 'complete');

        expect($target->refresh()->status)->toBe(MessageStatus::Complete)
            ->and($target->content)->toBe('Edited question')
            ->and($assistant->refresh()->status)->toBe(MessageStatus::Complete)
            ->and($assistant->content)->toBe('Fresh answer');
    });

    it('leaves every message unchanged when the provider fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(2),
        ]);
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AiException::providerError('openai'));
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_failed');

        expect($conversation->messages()->count())->toBe(2)
            ->and($target->refresh()->content)->toBe('Original question')
            ->and($assistant->refresh()->content)->toBe('Original answer');

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });

    it('does not mark the edited user message complete when the provider fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Pending,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AiException::providerError('openai'));
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_failed');

        expect($target->refresh()->content)->toBe('Original question')
            ->and($target->status)->toBe(MessageStatus::Pending)
            ->and($conversation->messages()->count())->toBe(1);
    });

    it('rolls back the edit when the assistant response cannot be persisted', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Pending,
        ]);
        $persistenceException = new RuntimeException('Unable to persist the assistant message.');

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Fresh answer', 'openai', 'gpt-test'));
        });

        Message::creating(function () use ($persistenceException): void {
            throw $persistenceException;
        });

        $this->withoutExceptionHandling();

        $caught = null;

        try {
            $this->actingAs($user, 'sanctum')
                ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                    'content' => 'Edited question',
                ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($persistenceException)
            ->and($target->refresh()->content)->toBe('Original question')
            ->and($target->status)->toBe(MessageStatus::Pending)
            ->and($conversation->messages()->count())->toBe(1);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });

    it('forbids editing assistant messages without calling the provider', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Assistant answer',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$assistant->id}", [
                'content' => 'Trying to edit the assistant',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect($assistant->refresh()->content)->toBe('Assistant answer')
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rejects editing a user message followed by another user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Second question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => 'Edited question',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا يمكن تعديل الرسالة لأنها متبوعة برسالة أخرى.');

        expect($target->refresh()->content)->toBe('First question')
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });
});

describe('editing validation', function () {
    it('validates edited content', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.content.0', 'المحتوى مطلوب.');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => ['invalid'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.content.0', 'يجب أن يكون المحتوى نصًا.');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                'content' => str_repeat('a', 10001),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.content.0', 'يجب ألا يزيد المحتوى عن 10000 حرفًا.');

        expect($target->refresh()->content)->toBe('Original question')
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });
});

describe('editing authorization and lookup', function () {
    it('forbids editing in another user conversation', function () {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$message->id}", [
                'content' => 'Edited',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });

    it('returns not found when the message belongs to another conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $otherConversation = Conversation::factory()->for($user)->create();
        $message = Message::factory()->for($otherConversation)->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$message->id}", [
                'content' => 'Edited',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('returns not found for an unknown message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}/messages/".Str::ulid(), [
                'content' => 'Edited',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('requires authentication', function () {
        $this->patchJson('/api/v1/conversations/'.Str::ulid().'/messages/'.Str::ulid(), [
            'content' => 'Edited',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
