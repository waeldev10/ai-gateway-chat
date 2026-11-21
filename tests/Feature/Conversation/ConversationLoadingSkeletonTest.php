<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows a search skeleton instead of an empty flash on the initial page load', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Deferred Search Conversation']);

    $this->actingAs($user);

    $this->get(route('conversations.search'))
        ->assertOk()
        ->assertSee('animate-pulse', false)
        ->assertSee('__lazyLoad', false)
        ->assertSee('جارٍ تحميل المحادثات')
        ->assertDontSee('Deferred Search Conversation');
});

it('shows a sidebar skeleton instead of an empty flash on the initial page load', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Deferred Sidebar Conversation']);

    $this->actingAs($user);

    $this->get(route('conversations.create'))
        ->assertOk()
        ->assertSee('animate-pulse', false)
        ->assertSee('__lazyLoad', false)
        ->assertSee('جارٍ تحميل المحادثات')
        ->assertDontSee('Deferred Sidebar Conversation');
});

it('shows a sidebar skeleton only while refreshing sidebar data', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(SidebarConversations::class)
        ->assertSee('جارٍ تحديث المحادثات')
        ->assertSeeHtml('wire:target="refreshList,conversations-changed"')
        ->assertSeeHtml('wire:loading.remove')
        ->assertSee('animate-pulse', false);
});

it('keeps search results and load more separate from the search refresh skeleton', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create([
        'title' => 'Visible Result',
        'updated_at' => now(),
    ]);
    Conversation::factory()->for($user)->count(12)->create([
        'updated_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->assertSee('Visible Result')
        ->assertSee('جارٍ تحديث نتائج البحث')
        ->assertSeeHtml('wire:target="search,refreshResults,conversations-changed"')
        ->assertSeeHtml('wire:target="loadMore"')
        ->assertSee('عرض المزيد');
});
