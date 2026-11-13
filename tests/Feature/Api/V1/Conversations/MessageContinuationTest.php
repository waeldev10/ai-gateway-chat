<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest as AiRequestData;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
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

describe('continuing conversations', function () {
    it('generates a new assistant reply from completed messages only', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First question',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(5),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::Assistant,
            'content' => 'First answer',
            'status' => MessageStatus::Complete,
            'created_at' => now()->subMinutes(4),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Pending question',
            'status' => MessageStatus::Pending,
            'created_at' => now()->subMinutes(3),
        ]);
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'Failed question',
            'status' => MessageStatus::Failed,
            'created_at' => now()->subMinutes(2),
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(Mockery::on(fn (AiRequestData $request): bool => $request->messages === withDefaultSystemPrompt([
                    ['role' => 'user', 'content' => 'First question'],
                    ['role' => 'assistant', 'content' => 'First answer'],
                ])))
                ->andReturn(new AiResponse(
                    'Continued answer',
                    'openai',
                    'gpt-test',
                    'stop',
                    AiUsage::fromTokens(10, 5),
                ));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
            ->assertOk()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Continued answer')
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('message', 'تم متابعة المحادثة بنجاح.');

        $assistant = $conversation->messages()
            ->where('role', MessageRole::Assistant)
            ->latest('created_at')
            ->latest('id')
            ->first();

        expect($assistant)->not->toBeNull()
            ->and($assistant->content)->toBe('Continued answer')
            ->and($conversation->messages()->count())->toBe(5);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Completed)
            ->and($aiRequest->message_id)->toBe($assistant->id)
            ->and($aiRequest->input_tokens)->toBe(10)
            ->and($aiRequest->output_tokens)->toBe(5)
            ->and($aiRequest->total_tokens)->toBe(15);
    });

    it('rejects continuation without completed messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->for($conversation)->create([
            'status' => MessageStatus::Failed,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.conversation.0', 'لا توجد رسائل مكتملة لمتابعة المحادثة.');

        expect($conversation->messages()->count())->toBe(1)
            ->and(AiRequestRecord::query()->count())->toBe(0);
    });

    it('does not create a message when the provider fails', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'A complete question',
            'status' => MessageStatus::Complete,
        ]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AiException::providerError('openai'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_failed');

        expect($conversation->messages()->count())->toBe(1);

        $aiRequest = AiRequestRecord::query()->sole();

        expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
            ->and($aiRequest->message_id)->toBeNull()
            ->and($aiRequest->error_message)->not->toBeNull();
    });

    it('forbids continuing another user conversation without calling the provider', function () {
        $conversation = Conversation::factory()->create();
        Message::factory()->for($conversation)->create(['status' => MessageStatus::Complete]);

        $this->mock(AiService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect(AiRequestRecord::query()->count())->toBe(0);
    });

    it('returns not found for an unknown conversation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/conversations/'.Str::ulid().'/messages/continue')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/conversations/'.Str::ulid().'/messages/continue')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});
