<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('pinning', function () {
    it('pins and unpins a conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.is_pinned', true)
            ->assertJsonPath('message', 'تم تثبيت المحادثة بنجاح.');

        expect($conversation->refresh()->pinned_at)->not->toBeNull();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversation->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false)
            ->assertJsonPath('message', 'تم إلغاء تثبيت المحادثة بنجاح.');

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });

    it('does not change the pin timestamp when pinning twice', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $conversation->update(['pinned_at' => now()->subHour()]);
        $pinnedAt = $conversation->refresh()->pinned_at;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/pin")
            ->assertOk();

        expect($conversation->refresh()->pinned_at->equalTo($pinnedAt))->toBeTrue();
    });

    it('forbids pinning another user conversation', function () {
        $conversation = Conversation::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/pin")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect($conversation->refresh()->pinned_at)->toBeNull();
    });
});

describe('bulk deletion', function () {
    it('deletes selected conversations and their messages', function () {
        $user = User::factory()->create();
        $keep = Conversation::factory()->for($user)->create();
        $first = Conversation::factory()->for($user)->create();
        $second = Conversation::factory()->for($user)->create();
        Message::factory()->for($first)->create();
        Message::factory()->for($second)->create();
        Message::factory()->for($keep)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم حذف المحادثات المحددة بنجاح.');

        $this->assertDatabaseMissing('conversations', ['id' => $first->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $second->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $first->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $second->id]);
        $this->assertDatabaseHas('conversations', ['id' => $keep->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $keep->id]);
    });

    it('is all-or-nothing when a foreign conversation is included', function () {
        $user = User::factory()->create();
        $mine = Conversation::factory()->for($user)->create();
        $theirs = Conversation::factory()->create();
        Message::factory()->for($mine)->create();
        Message::factory()->for($theirs)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => [$mine->id, $theirs->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('conversations', ['id' => $mine->id]);
        $this->assertDatabaseHas('conversations', ['id' => $theirs->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $mine->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $theirs->id]);
    });

    it('is all-or-nothing when a conversation does not exist', function () {
        $user = User::factory()->create();
        $mine = Conversation::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => [$mine->id, (string) Str::ulid()],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('conversations', ['id' => $mine->id]);
    });

    it('validates the bulk delete payload', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', ['ids' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/conversations', [
                'ids' => collect(range(1, 51))->map(fn (): string => (string) Str::ulid())->all(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    });
});

describe('authentication', function () {
    it('requires authentication', function (string $method, string $uri) {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    })->with([
        ['POST', '/api/v1/conversations/'.Str::ulid().'/pin'],
        ['DELETE', '/api/v1/conversations/'.Str::ulid().'/pin'],
        ['DELETE', '/api/v1/conversations'],
    ]);
});
