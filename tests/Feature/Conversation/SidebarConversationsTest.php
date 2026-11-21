<?php

use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\ConversationSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists the authenticated user conversations with pinned ones first', function () {
    $user = User::factory()->create();

    Conversation::factory()->for($user)->create([
        'title' => 'Pinned Older',
        'pinned_at' => now()->subHour(),
    ]);
    Conversation::factory()->for($user)->create([
        'title' => 'Pinned Newer',
        'pinned_at' => now(),
    ]);
    Conversation::factory()->for($user)->create([
        'title' => 'Recent Older',
        'updated_at' => now()->subDay(),
    ]);
    Conversation::factory()->for($user)->create([
        'title' => 'Recent Newer',
        'updated_at' => now(),
    ]);
    Conversation::factory()->for(User::factory())->create([
        'title' => 'Foreign Conversation',
    ]);

    $this->actingAs($user);

    Livewire::test(SidebarConversations::class)
        ->assertSee('Pinned Newer')
        ->assertSee('Pinned Older')
        ->assertSee('Recent Newer')
        ->assertSee('Recent Older')
        ->assertDontSee('Foreign Conversation')
        ->assertSeeInOrder([
            'Pinned Newer',
            'Pinned Older',
            'Recent Newer',
            'Recent Older',
        ]);
});

it('refreshes the list when conversations change', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(SidebarConversations::class)
        ->assertDontSee('Fresh Conversation');

    Conversation::factory()->for($user)->create([
        'title' => 'Fresh Conversation',
    ]);

    $component->dispatch('conversations-changed')
        ->assertSee('Fresh Conversation');
});

it('uses a friendly fallback for conversations without a title', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => null]);

    $this->actingAs($user);

    Livewire::test(SidebarConversations::class)
        ->assertSee('محادثة جديدة');
});

it('caps the sidebar at the latest ten conversations and offers more', function () {
    $user = User::factory()->create();

    foreach (range(1, 15) as $i) {
        Conversation::factory()->for($user)->create([
            'title' => sprintf('Conversation %02d', $i),
            'updated_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $this->actingAs($user);

    $groups = app(ConversationSearchService::class)->sidebarFor($user);

    expect($groups['pinned'])->toHaveCount(0)
        ->and($groups['recent'])->toHaveCount(ConversationSearchService::SIDEBAR_LIMIT)
        ->and($groups['recent']->first()->title)->toBe('Conversation 15')
        ->and($groups['recent']->last()->title)->toBe('Conversation 06')
        ->and($groups['hasMore'])->toBeTrue();

    Livewire::test(SidebarConversations::class)
        ->assertSee('Conversation 15')
        ->assertSee('Conversation 06')
        ->assertDontSee('Conversation 05')
        ->assertDontSee('Conversation 01')
        ->assertSee('عرض المزيد')
        ->assertSee('href="'.route('conversations.search').'"', false);
});

it('counts pinned conversations within the single sidebar limit', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        Conversation::factory()->for($user)->create([
            'title' => sprintf('Pinned %02d', $i),
            'pinned_at' => now()->subMinutes(10 - $i),
        ]);
    }

    foreach (range(1, 10) as $i) {
        Conversation::factory()->for($user)->create([
            'title' => sprintf('Recent %02d', $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ]);
    }

    $this->actingAs($user);

    $groups = app(ConversationSearchService::class)->sidebarFor($user);

    expect($groups['pinned'])->toHaveCount(5)
        ->and($groups['recent'])->toHaveCount(5)
        ->and($groups['pinned']->first()->title)->toBe('Pinned 05')
        ->and($groups['recent']->first()->title)->toBe('Recent 10')
        ->and($groups['recent']->last()->title)->toBe('Recent 06')
        ->and($groups['hasMore'])->toBeTrue();
});

it('keeps an active conversation visible outside the recent cap', function () {
    $user = User::factory()->create();

    $active = Conversation::factory()->for($user)->create([
        'title' => 'Old Active',
        'updated_at' => now()->subDays(5),
    ]);

    Conversation::factory()->for($user)->count(10)->create([
        'updated_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(SidebarConversations::class, ['activeId' => (string) $active->id])
        ->assertSee('Old Active')
        ->assertSee('aria-current="page"', false);
});
