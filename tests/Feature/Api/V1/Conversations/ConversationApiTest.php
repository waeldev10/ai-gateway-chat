<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('listing', function () {
    it('lists only conversations owned by the authenticated user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $first = Conversation::factory()->for($user)->create(['title' => 'First']);
        $second = Conversation::factory()->for($user)->create(['title' => 'Second']);
        $foreign = Conversation::factory()->for($otherUser)->create(['title' => 'Foreign']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'pinned_at', 'created_at', 'updated_at'],
                ],
            ])
            ->assertJsonMissingPath('data.0.messages');

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toHaveCount(2)
            ->and($ids)->toContain($first->id)
            ->and($ids)->toContain($second->id)
            ->and($ids)->not->toContain($foreign->id);
    });
});

describe('creation', function () {
    it('creates a conversation with a title', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/conversations', ['title' => 'Planning a trip'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Planning a trip')
            ->assertJsonPath('message', 'تم إنشاء المحادثة بنجاح.')
            ->assertJsonStructure([
                'data' => ['id', 'title', 'pinned_at', 'created_at', 'updated_at'],
            ])
            ->assertJsonMissingPath('data.messages');

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'title' => 'Planning a trip',
        ]);
    });

    it('creates a conversation without a title', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/conversations')
            ->assertCreated()
            ->assertJsonPath('data.title', null);

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'title' => null,
        ]);
    });

    it('validates the title with Arabic messages', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/conversations', ['title' => ['invalid']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.title.0', 'يجب أن يكون العنوان نصًا.');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/conversations', ['title' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.title.0', 'يجب ألا يزيد العنوان عن 255 حرفًا.');
    });
});

// describe('showing', function () {
//     it('returns a conversation with its messages in chronological order', function () {
//         $user = User::factory()->create();
//         $conversation = Conversation::factory()->for($user)->create();
//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::User,
//             'content' => 'First message',
//             'created_at' => now()->subMinutes(2),
//         ]);
//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::Assistant,
//             'content' => 'Second message',
//             'created_at' => now()->subMinute(),
//         ]);

//         $this->actingAs($user, 'sanctum')
//             ->getJson("/api/v1/conversations/{$conversation->id}")
//             ->assertOk()
//             ->assertJsonPath('data.id', $conversation->id)
//             ->assertJsonPath('data.messages.0.content', 'First message')
//             ->assertJsonPath('data.messages.1.content', 'Second message')
//             ->assertJsonStructure([
//                 'data' => [
//                     'id',
//                     'title',
//                     'pinned_at',
//                     'created_at',
//                     'updated_at',
//                     'messages' => [
//                         '*' => ['id', 'role', 'content', 'status', 'created_at', 'updated_at'],
//                     ],
//                 ],
//             ])
//             ->assertJsonMissingPath('data.user_id');
//     });

//     it('forbids viewing another user conversation', function () {
//         $owner = User::factory()->create();
//         $conversation = Conversation::factory()->for($owner)->create();

//         $this->actingAs(User::factory()->create(), 'sanctum')
//             ->getJson("/api/v1/conversations/{$conversation->id}")
//             ->assertForbidden()
//             ->assertJsonPath('code', 'forbidden');
//     });

//     it('returns not found for an unknown conversation', function () {
//         $this->actingAs(User::factory()->create(), 'sanctum')
//             ->getJson('/api/v1/conversations/'.Str::ulid())
//             ->assertNotFound()
//             ->assertJsonPath('code', 'not_found');
//     });
// });

describe('showing', function () {
    it('returns conversation metadata without messages', function () {
        $user = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($user)
            ->create();

        Message::factory()->for($conversation)->create([
            'role' => MessageRole::User,
            'content' => 'First message',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'is_pinned',
                    'pinned_at',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonMissingPath('data.messages')
            ->assertJsonMissingPath('data.user_id');
    });

    it('does not query messages when returning conversation metadata', function () {
        $user = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($user)
            ->create();

        Message::factory()->for($conversation)->create();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.messages');

        expect(collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "messages"'),
        ))->toBeEmpty();
    });

    it('forbids viewing another user conversation', function () {
        $owner = User::factory()->create();

        $conversation = Conversation::factory()
            ->for($owner)
            ->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    });

    it('returns not found for an unknown conversation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/conversations/'.Str::ulid())
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('renaming', function () {
    it('renames a conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Old title']);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}", ['title' => 'New title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('message', 'تم تحديث المحادثة بنجاح.');

        expect($conversation->refresh()->title)->toBe('New title');
    });

    it('validates the new title with Arabic messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.title.0', 'العنوان مطلوب.');
    });

    it('forbids renaming another user conversation', function () {
        $conversation = Conversation::factory()->create(['title' => 'Original']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->patchJson("/api/v1/conversations/{$conversation->id}", ['title' => 'Hijacked'])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        expect($conversation->refresh()->title)->toBe('Original');
    });
});

describe('deletion', function () {
    it('deletes a conversation and its messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->for($conversation)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', 'تم حذف المحادثة بنجاح.');

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    });

    it('forbids deleting another user conversation', function () {
        $conversation = Conversation::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    });
});

describe('authentication', function () {
    it('requires authentication', function (string $method, string $uri) {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJsonPath('type', 'about:blank')
            ->assertJsonPath('status', 401)
            ->assertJsonPath('code', 'unauthenticated');
    })->with([
        ['GET', '/api/v1/conversations'],
        ['POST', '/api/v1/conversations'],
        ['GET', '/api/v1/conversations/'.Str::ulid()],
    ]);
});
