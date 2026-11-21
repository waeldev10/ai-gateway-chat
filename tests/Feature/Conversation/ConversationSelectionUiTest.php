<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('themes every conversation selection checkbox with the dynamic primary color', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.ui-checkbox')
        ->toContain('accent-color: var(--ui-primary)')
        ->toContain('border-color: var(--ui-primary)')
        ->toContain('outline: 2px solid var(--ui-ring)');
});

it('marks selection and select-all checkboxes for primary theming', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create(['title' => 'Selectable Conversation']);

    $this->actingAs($user);

    $html = Livewire::test(ConversationSearch::class)->html();

    expect(substr_count($html, 'ui-checkbox'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('border-primary')
        ->and($html)->toContain('data-conversation-select');
});

it('keeps the selection scope above the lazy component boundary', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create();

    $html = $this->actingAs($user)
        ->get(route('conversations.search'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('selected: []')
        ->toContain('new MutationObserver')
        ->toContain('__lazyLoad');
});

it('derives select-all state from the currently visible selection', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create();

    $html = $this->actingAs($user)
        ->withHeader('X-Livewire-Navigate', 'true')
        ->get(route('conversations.search'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain(':checked="allChecked"')
        ->toContain('x-effect="$el.indeterminate = someChecked"')
        ->toContain('@change="toggleAll($event.target.checked)"')
        ->toContain('new MutationObserver')
        ->toContain('visibleIds()')
        ->not->toContain('x-ref="selectAll"');
});

it('reactively reveals the bulk toolbar from the selected count', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationSearch::class)
        ->assertSeeHtml('x-cloak')
        ->assertSeeHtml(":class=\"{ 'hidden': selected.length === 0, 'flex': selected.length > 0 }\"")
        ->assertSeeHtml('x-text="selected.length"')
        ->assertSeeHtml('@click="clearSelection()"')
        ->assertSeeHtml('@click="openBulkDelete()"')
        ->assertDontSeeHtml('x-show="selected.length > 0"');
});

it('shows an exact Arabic bulk delete count in the confirmation dialog', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->create();

    $html = $this->actingAs($user)
        ->withHeader('X-Livewire-Navigate', 'true')
        ->get(route('conversations.search'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('bulkDeleteMessage(count)')
        ->toContain('هل أنت متأكد من حذف محادثة واحدة محددة؟')
        ->toContain('هل أنت متأكد من حذف محادثتين محددتين؟')
        ->toContain("count + ' محادثات محددة؟'")
        ->toContain("count + ' محادثة محددة؟'")
        ->toContain('message: this.bulkDeleteMessage(ids.length)')
        ->toContain('payload: { ids: ids }')
        ->toContain('messageText = $event.detail.message')
        ->toContain('x-text="messageText"');
});
