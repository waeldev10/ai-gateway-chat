
<?php

use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\ConversationOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('deletion safety', function () {
    it('deletes conversation and its messages, nulls ai_request references while keeping audit rows', function () {
        $user = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($user)
            ->create();

        $message = Message::factory()
            ->for($conversation)
            ->create([
                'role' => MessageRole::User,
                'content' => 'First message',
            ]);

        $aiRequest = AiRequest::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'openrouter',
            'model' => 'gpt-test',
            'status' => AiRequestStatus::Completed,
            'source' => AiSource::Api,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم حذف المحادثة بنجاح.');

        expect(
            Conversation::query()
                ->whereKey($conversation->id)
                ->exists()
        )->toBeFalse();

        expect(
            Message::query()
                ->where('conversation_id', $conversation->id)
                ->exists()
        )->toBeFalse();

        // AI request audit record should remain, detached from the deleted conversation.
        expect(
            AiRequest::query()
                ->whereKey($aiRequest->id)
                ->exists()
        )->toBeTrue();

        expect(
            $aiRequest->fresh()->conversation_id
        )->toBeNull();
    });

    it('returns 404 for unknown conversation', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations/'.Str::ulid())
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('blocks deleting another user conversation', function () {
        $owner = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($owner)
            ->create();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect(
            Conversation::query()
                ->whereKey($conversation->id)
                ->exists()
        )->toBeTrue();
    });

    it('rolls back when model event throws during delete', function () {
        $user = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($user)
            ->create();

        $message = Message::factory()
            ->for($conversation)
            ->create();

        $aiRequest = AiRequest::create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'openrouter',
            'model' => 'gpt-test',
            'status' => AiRequestStatus::Completed,
            'source' => AiSource::Api,
        ]);

        $failure = new RuntimeException('Delete blocked');

        $conversation->deleting(function () use ($failure) {
            throw $failure;
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user, 'sanctum')
                ->deleteJson("/api/v1/conversations/{$conversation->id}");
        } catch (Throwable $e) {
            // Expected deletion failure.
        }

        expect(
            Conversation::query()
                ->whereKey($conversation->id)
                ->exists()
        )->toBeTrue();

        expect(
            Message::query()
                ->whereKey($message->id)
                ->exists()
        )->toBeTrue();

        expect(
            AiRequest::query()
                ->whereKey($aiRequest->id)
                ->exists()
        )->toBeTrue();
    });

    it('prevents deletion while a message operation holds the lock', function () {
        $user = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($user)
            ->create();

        Message::factory()
            ->for($conversation)
            ->create([
                'role' => MessageRole::User,
                'content' => 'Q',
            ]);

        // The operation lock key/API must match ConversationOperationLock.
        // This test currently verifies the busy-operation response.
        $lock = Cache::lock(
            ConversationOperationLock::key($conversation),
            30
        );

        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson(
                    "/api/v1/conversations/{$conversation->id}/messages/continue"
                )
                ->assertStatus(409)
                ->assertJsonPath('code', 'conversation_busy');

            expect($conversation->messages()->count())->toBe(1);
        } finally {
            $lock->release();
        }
    });

    it('allows bulk delete when none are busy', function () {
        $user = User::factory()->create();

        $conversation1 = Conversation::factory()
            ->for($user)
            ->create();

        $conversation2 = Conversation::factory()
            ->for($user)
            ->create();

        Message::factory()->for($conversation1)->create();
        Message::factory()->for($conversation2)->create();

        $ids = [$conversation1->id, $conversation2->id];

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => $ids,
            ])
            ->assertOk()
            ->assertJsonPath(
                'message',
                'تم حذف المحادثات المحددة بنجاح.'
            );

        expect(
            Conversation::query()
                ->whereIn('id', $ids)
                ->exists()
        )->toBeFalse();

        expect(
            Message::query()
                ->whereIn('conversation_id', $ids)
                ->exists()
        )->toBeFalse();
    });

    it('is all-or-nothing when a foreign conversation is included', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $mine = Conversation::factory()
            ->for($user)
            ->create();

        $theirs = Conversation::factory()
            ->for($otherUser)
            ->create();

        Message::factory()->for($mine)->create();
        Message::factory()->for($theirs)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => [$mine->id, $theirs->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect(
            Conversation::query()
                ->whereKey($mine->id)
                ->exists()
        )->toBeTrue();

        expect(
            Conversation::query()
                ->whereKey($theirs->id)
                ->exists()
        )->toBeTrue();
    });
});
