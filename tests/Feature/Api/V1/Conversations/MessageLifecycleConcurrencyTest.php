<?php

use App\AI\AiService;
use App\AI\DTOs\AiResponse;
use App\Enums\AiRequestStatus;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\ConversationOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

describe('continuation lifecycle', function () {
    it('rejects continuation while the latest message is pending', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Answered question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Unfinished answer',
            'status' => MessageStatus::Pending,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.conversation.0', 'لا يمكن متابعة المحادثة لأن آخر رسالة قيد المعالجة.');

        expect($conversation->messages()->count())->toBe(2)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rejects continuation while another operation holds the conversation lock', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $lock = Cache::lock(ConversationOperationLock::key($conversation), 30);

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
                ->assertStatus(409)
                ->assertJsonPath('title', 'Conversation Busy')
                ->assertJsonPath('code', 'conversation_busy');
        } finally {
            $lock->release();
        }

        expect($conversation->messages()->count())->toBe(1)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('does not persist an assistant message when continuation persistence fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $persistenceException = new RuntimeException('Unable to persist the continuation.');

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Generated answer', 'openai', 'gpt-test'));
        });

        Message::creating(function () use ($persistenceException): void {
            throw $persistenceException;
        });

        $this->withoutExceptionHandling();

        $caught = null;

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue");
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($persistenceException)
            ->and($conversation->messages()->count())->toBe(1);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });
});

describe('regeneration lifecycle', function () {
    it('rejects regenerating an incomplete user message', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Pending question',
            'status' => MessageStatus::Pending,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا يمكن إعادة توليد الرد لرسالة مستخدم غير مكتملة.');

        expect($target->refresh()->content)->toBe('Pending question')
            ->and($conversation->messages()->count())->toBe(1)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rejects regenerating an assistant when no completed user message exists', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Orphaned answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Target answer',
            'status' => MessageStatus::Complete,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.message.0', 'لا توجد رسائل مكتملة كافية لإعادة توليد الرد.');

        expect($target->refresh()->content)->toBe('Target answer')
            ->and($conversation->messages()->count())->toBe(2)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rejects regeneration while another operation holds the conversation lock', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $lock = Cache::lock(ConversationOperationLock::key($conversation), 30);

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate")
                ->assertStatus(409)
                ->assertJsonPath('title', 'Conversation Busy')
                ->assertJsonPath('code', 'conversation_busy');
        } finally {
            $lock->release();
        }

        expect($target->refresh()->content)->toBe('Original answer')
            ->and($target->status)->toBe(MessageStatus::Complete)
            ->and($conversation->messages()->count())->toBe(2)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rolls back the replacement when persisting the regenerated assistant fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $persistenceException = new RuntimeException('Unable to persist the regenerated assistant.');

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Fresh answer', 'openai', 'gpt-test'));
        });

        Message::updating(function (Message $message) use ($persistenceException): void {
            if ($message->role === MessageRole::Assistant && $message->isDirty('content')) {
                throw $persistenceException;
            }
        });

        $this->withoutExceptionHandling();

        $caught = null;

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}/regenerate");
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($persistenceException)
            ->and($target->refresh()->content)->toBe('Original answer')
            ->and($target->status)->toBe(MessageStatus::Complete)
            ->and($conversation->messages()->count())->toBe(2);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });
});

describe('editing lifecycle', function () {
    it('rejects editing while another operation holds the conversation lock', function () {
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

        $lock = Cache::lock(ConversationOperationLock::key($conversation), 30);

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->patchJson("/api/v1/conversations/{$conversation->id}/messages/{$target->id}", [
                    'content' => 'Edited question',
                ])
                ->assertStatus(409)
                ->assertJsonPath('title', 'Conversation Busy')
                ->assertJsonPath('code', 'conversation_busy');
        } finally {
            $lock->release();
        }

        expect($target->refresh()->content)->toBe('Original question')
            ->and($conversation->messages()->count())->toBe(1)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('rolls back the user edit when replacing an existing assistant response fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $persistenceException = new RuntimeException('Unable to persist the edited exchange.');

        $target = Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Original question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinute(),
        ]);
        $assistant = Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'Original answer',
            'status' => MessageStatus::Complete,
            'created_at' => now(),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Edited answer', 'openai', 'gpt-test'));
        });

        Message::updating(function (Message $message) use ($persistenceException): void {
            if ($message->role === MessageRole::Assistant && $message->isDirty('content')) {
                throw $persistenceException;
            }
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
            ->and($target->status)->toBe(MessageStatus::Complete)
            ->and($assistant->refresh()->content)->toBe('Original answer')
            ->and($assistant->status)->toBe(MessageStatus::Complete)
            ->and($conversation->messages()->count())->toBe(2);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull();
    });
});

describe('send lifecycle', function () {
    it('allows retrying after the conversation is no longer busy', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Answer', 'openai', 'gpt-test'));
        });

        $lock = Cache::lock(ConversationOperationLock::key($conversation), 30);

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                    'content' => 'First question',
                ])
                ->assertStatus(409)
                ->assertJsonPath('title', 'Conversation Busy')
                ->assertJsonPath('code', 'conversation_busy');
        } finally {
            $lock->release();
        }

        expect($conversation->messages()->count())->toBe(0)
            ->and(AiRequestRecord::query()->count())->toBe(0);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'content' => 'First question',
            ])
            ->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Answer');

        expect($conversation->messages()->count())->toBe(2)
            ->and(AiRequestRecord::query()->count())->toBe(1);
    });

    it('does not block operations on another conversation', function () {
        $user = User::factory()->create();
        $busyConversation = Conversation::factory()->for($user)->create();
        $otherConversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($otherConversation)->create([
            'role' => MessageRole::User,
            'content' => 'Other question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse('Other answer', 'openai', 'gpt-test'));
        });

        $lock = Cache::lock(ConversationOperationLock::key($busyConversation), 30);

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/v1/conversations/{$otherConversation->id}/messages/continue")
                ->assertOk()
                ->assertJsonPath('data.content', 'Other answer');
        } finally {
            $lock->release();
        }

        expect($otherConversation->messages()->count())->toBe(2)
            ->and($busyConversation->messages()->count())->toBe(0);
    });
});
