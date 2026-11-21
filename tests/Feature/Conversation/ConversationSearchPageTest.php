<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Livewire\Conversation\ConversationSearch;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\ConversationSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires authentication for the conversations search page', function () {
    $this->get(route('conversations.search'))
        ->assertRedirect(route('login'));
});

it('renders the conversations search page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('conversations.search'))
        ->assertOk()
        ->assertSee('بحث المحادثات')
        ->assertSeeLivewire(ConversationSearch::class)
        ->assertSee('animate-pulse', false)
        ->assertSee('__lazyLoad', false)
        ->assertSee('href="'.route('conversations.search').'"', false)
        ->assertSee('aria-current="page"', false);
});

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

    Livewire::test(ConversationSearch::class)
        ->assertSee('Pinned Newer')
        ->assertSee('Pinned Older')
        ->assertSee('Recent Newer')
        ->assertSee('Recent Older')
        ->assertDontSee('Foreign Conversation')
        ->assertSee('المثبتة')
        ->assertSee('الأخيرة')
        ->assertSeeInOrder([
            'Pinned Newer',
            'Pinned Older',
            'Recent Newer',
            'Recent Older',
        ]);
});

it('filters conversations by title', function () {
    $user = User::factory()->create();

    Conversation::factory()->for($user)->create(['title' => 'Quarterly Planning']);
    Conversation::factory()->for($user)->create(['title' => 'Grocery List']);
    Conversation::factory()->for(User::factory())->create(['title' => 'Quarterly Foreign']);

    $this->actingAs($user);

    $component = Livewire::test(ConversationSearch::class)
        ->set('search', 'Quarterly')
        ->assertSee('Quarterly Planning')
        ->assertDontSee('Grocery List')
        ->assertDontSee('Quarterly Foreign');

    expect($component->viewData('results')->total())->toBe(1);
});

it('reads the search query from the url', function () {
    $user = User::factory()->create();

    Conversation::factory()->for($user)->create(['title' => 'Searchable Conversation']);
    Conversation::factory()->for($user)->create(['title' => 'Hidden Conversation']);

    $this->actingAs($user);

    $this->get(route('conversations.search', ['search' => 'Searchable']))
        ->assertOk()
        ->assertSeeLivewire(ConversationSearch::class)
        ->assertSee('__lazyLoad', false);

    Livewire::withQueryParams(['search' => 'Searchable'])
        ->test(ConversationSearch::class)
        ->assertSet('search', 'Searchable')
        ->assertSee('Searchable Conversation')
        ->assertSee('عدد النتائج: 1');
});

it('shows an empty state when no conversations match the search', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->set('search', 'does-not-exist')
        ->assertSee('لا توجد نتائج مطابقة')
        ->assertSee('does-not-exist');
});

it('shows the first page and then loads older results', function () {
    $user = User::factory()->create();

    foreach (range(1, ConversationSearch::PAGE_SIZE + 5) as $i) {
        Conversation::factory()->for($user)->create([
            'title' => sprintf('Conversation %02d', $i),
            'updated_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $this->actingAs($user);

    $component = Livewire::test(ConversationSearch::class)
        ->assertSet('perPage', ConversationSearch::PAGE_SIZE);

    expect($component->viewData('results')->count())->toBe(ConversationSearch::PAGE_SIZE)
        ->and($component->viewData('results')->total())->toBe(ConversationSearch::PAGE_SIZE + 5);

    $component->call('loadMore')
        ->assertSet('perPage', ConversationSearch::PAGE_SIZE * 2);

    expect($component->viewData('results')->count())->toBe(ConversationSearch::PAGE_SIZE + 5);
});

it('caps load more at the service maximum', function () {
    $user = User::factory()->create();

    Conversation::factory()->for($user)
        ->count(ConversationSearchService::MAX_PER_PAGE + 5)
        ->create();

    $this->actingAs($user);

    $component = Livewire::test(ConversationSearch::class);

    for ($i = 0; $i < 10; $i++) {
        $component->call('loadMore');
    }

    $component->assertSet('perPage', ConversationSearchService::MAX_PER_PAGE)
        ->assertSee('تم عرض أحدث '.ConversationSearchService::MAX_PER_PAGE.' محادثة');
});

it('clears the search and resets the page size', function () {
    $user = User::factory()->create();

    Conversation::factory()->for($user)->create(['title' => 'Visible Conversation']);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->set('search', 'Visible')
        ->call('loadMore')
        ->assertSet('perPage', ConversationSearch::PAGE_SIZE * 2)
        ->call('clear')
        ->assertSet('search', '')
        ->assertSet('perPage', ConversationSearch::PAGE_SIZE)
        ->assertSee('Visible Conversation');
});

it('deletes a single selected conversation through the bulk action', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Delete Me',
    ]);
    $message = Conversation::factory()->for($user)->create([
        'title' => 'Keep Me',
    ]);
    $deletedMessage = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Hello',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->call('deleteConversations', [(string) $conversation->id])
        ->assertDispatched('close-confirm-modal', id: 'search-delete')
        ->assertDispatched('conversations-changed')
        ->assertDispatched('toast', type: 'success')
        ->assertDontSee('Delete Me')
        ->assertSee('Keep Me');

    expect(Conversation::find($conversation->id))->toBeNull()
        ->and($deletedMessage->fresh())->toBeNull()
        ->and(Conversation::find($message->id))->not->toBeNull();
});

it('deletes multiple selected conversations from the search page', function () {
    $user = User::factory()->create();
    $first = Conversation::factory()->for($user)->create(['title' => 'First']);
    $second = Conversation::factory()->for($user)->create(['title' => 'Second']);
    $kept = Conversation::factory()->for($user)->create(['title' => 'Kept']);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->call('deleteConversations', [(string) $first->id, (string) $second->id])
        ->assertDispatched('close-confirm-modal', id: 'search-delete')
        ->assertDispatched('conversations-changed')
        ->assertDispatched('toast', type: 'success')
        ->assertDontSee('First')
        ->assertDontSee('Second')
        ->assertSee('Kept');

    expect(Conversation::find($first->id))->toBeNull()
        ->and(Conversation::find($second->id))->toBeNull()
        ->and(Conversation::find($kept->id))->not->toBeNull();
});

it('rejects a bulk delete containing another user conversation', function () {
    $user = User::factory()->create();
    $owned = Conversation::factory()->for($user)->create(['title' => 'Owned']);
    $foreign = Conversation::factory()->for(User::factory())->create(['title' => 'Foreign']);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->call('deleteConversations', [(string) $owned->id, (string) $foreign->id])
        ->assertDispatched('close-confirm-modal', id: 'search-delete')
        ->assertDispatched('toast', type: 'error')
        ->assertNotDispatched('conversations-changed');

    expect(Conversation::find($owned->id))->not->toBeNull()
        ->and(Conversation::find($foreign->id))->not->toBeNull();
});

it('debounces the search field', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->assertSeeHtml('wire:model.live.debounce.500ms="search"');
});

it('renders selectable results and the bulk delete controls', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Selectable Conversation']);

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->assertSee('تحديد الكل')
        ->assertSee('حذف المحدد')
        ->assertSee('data-conversation-select', false)
        ->assertSee('openBulkDelete', false);
});

it('links the sidebar search action to the search page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('conversations.create'))
        ->assertOk()
        ->assertSee('href="'.route('conversations.search').'"', false)
        ->assertSee('aria-label="بحث"', false);

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $searchLink = $xpath->query('//a[@aria-label="بحث"]')->item(0);
    $scroll = $xpath->query('//*[@id="sidebar-conversations-scroll"]')->item(0);
    $userMenuButton = $xpath->query('//button[@aria-label="قائمة المستخدم"]')->item(0);
    $searchBeforeScroll = $xpath->query('//*[@id="sidebar-conversations-scroll"]/preceding::a[@aria-label="بحث"]')->item(0);
    $userMenuAfterScroll = $xpath->query('//*[@id="sidebar-conversations-scroll"]/following::button[@aria-label="قائمة المستخدم"]')->item(0);

    $isInside = function (DOMNode $node, DOMNode $ancestor): bool {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent === $ancestor) {
                return true;
            }
        }

        return false;
    };

    expect($searchLink)->not->toBeNull()
        ->and($scroll)->not->toBeNull()
        ->and($userMenuButton)->not->toBeNull()
        ->and($searchBeforeScroll)->not->toBeNull()
        ->and($userMenuAfterScroll)->not->toBeNull()
        ->and($searchLink->getAttribute('href'))->toBe(route('conversations.search'))
        ->and($isInside($searchLink, $scroll))->toBeFalse()
        ->and($isInside($userMenuButton, $scroll))->toBeFalse()
        ->and($searchLink->parentNode->parentNode->nodeName)->toBe('aside')
        ->and($userMenuButton->parentNode->parentNode->parentNode->nodeName)->toBe('aside');
});
