<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('search and pagination', function () {
    it('paginates owned conversations with pinned conversations first', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $pinned = Conversation::factory()->for($user)->create([
            'title' => 'Pinned conversation',
            'pinned_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $recent = Conversation::factory()->for($user)->create([
            'title' => 'Recent conversation',
            'updated_at' => now()->subMinute(),
        ]);
        $older = Conversation::factory()->for($user)->create([
            'title' => 'Older conversation',
            'updated_at' => now()->subHour(),
        ]);
        $foreign = Conversation::factory()->for($otherUser)->create(['title' => 'Foreign conversation']);

        $firstPage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $pinned->id)
            ->assertJsonPath('data.1.id', $recent->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'is_pinned', 'pinned_at', 'created_at', 'updated_at'],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
            ]);

        $secondPage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id);

        $ids = collect($firstPage->json('data'))
            ->merge($secondPage->json('data'))
            ->pluck('id');

        expect($ids)->not->toContain($foreign->id);
    });

    it('searches owned conversations by title', function () {
        $user = User::factory()->create();
        $match = Conversation::factory()->for($user)->create(['title' => 'Planning a trip to Japan']);
        Conversation::factory()->for($user)->create(['title' => 'Weekly groceries']);
        Conversation::factory()->for(User::factory())->create(['title' => 'Planning a secret trip']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?search=Planning')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('meta.total', 1);
    });

    it('supports the search route and q alias', function () {
        $user = User::factory()->create();
        $match = Conversation::factory()->for($user)->create(['title' => 'Arabic study notes']);
        Conversation::factory()->for($user)->create(['title' => 'Cooking recipes']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations/search?q=study')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    });

    it('validates search and pagination parameters', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?per_page=0')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/conversations?search[]=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    });

    it('requires authentication', function (string $uri) {
        $this->getJson($uri)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    })->with([
        '/api/v1/conversations',
        '/api/v1/conversations/search',
    ]);
});
