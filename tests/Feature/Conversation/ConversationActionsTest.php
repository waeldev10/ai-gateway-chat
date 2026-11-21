<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Livewire\Conversation\ConversationActions;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('mounts with the conversation title and share url', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Planning Session',
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->assertSet('conversationId', (string) $conversation->id)
        ->assertSet('title', 'Planning Session')
        ->assertSet('isPinned', false)
        ->assertSee('Planning Session')
        ->assertSee(route('conversations.show', $conversation));
});

it('toggles the conversation pin', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    $component = Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ]);

    $component->call('togglePin')
        ->assertSet('isPinned', true)
        ->assertDispatched('conversations-changed')
        ->assertDispatched('conversation-pin-changed', id: (string) $conversation->id, pinned: true)
        ->assertDispatched('toast');

    expect($conversation->fresh()->pinned_at)->not->toBeNull();

    $component->call('togglePin')
        ->assertSet('isPinned', false)
        ->assertDispatched('conversation-pin-changed', id: (string) $conversation->id, pinned: false);

    expect($conversation->fresh()->pinned_at)->toBeNull();
});

it('renames the conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Old Title',
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->call('rename', (string) $conversation->id, 'Renamed Title')
        ->assertSet('title', 'Renamed Title')
        ->assertDispatched('conversation-rename-succeeded', scope: 'page')
        ->assertDispatched('conversations-changed')
        ->assertDispatched('conversation-renamed', id: (string) $conversation->id, title: 'Renamed Title')
        ->assertDispatched('toast');

    expect($conversation->fresh()->title)->toBe('Renamed Title');
});

it('validates a rename', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Keep This',
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->call('rename', (string) $conversation->id, '   ')
        ->assertSet('title', 'Keep This')
        ->assertDispatched('conversation-rename-failed', scope: 'page')
        ->assertDispatched('toast', type: 'error');

    expect($conversation->fresh()->title)->toBe('Keep This');
});

it('syncs pin and title changes from other components', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Before Sync',
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->dispatch('conversation-pin-changed', id: (string) $conversation->id, pinned: true)
        ->assertSet('isPinned', true)
        ->dispatch('conversation-renamed', id: (string) $conversation->id, title: 'After Sync')
        ->assertSet('title', 'After Sync')
        ->dispatch('conversation-pin-changed', id: 'another-conversation', pinned: false)
        ->assertSet('isPinned', true)
        ->dispatch('conversation-renamed', id: 'another-conversation', title: 'Ignored')
        ->assertSet('title', 'After Sync');
});

it('does not mount for another user conversation', function () {
    $user = User::factory()->create();
    $foreign = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $foreign->id,
    ])->assertStatus(404);
});

it('deletes the conversation and redirects to the landing page', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Delete Me',
    ]);
    $message = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Hello',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->call('delete')
        ->assertDispatched('close-confirm-modal', id: 'page-delete')
        ->assertDispatched('conversations-changed')
        ->assertRedirect(route('conversations.create'));

    expect(Conversation::find($conversation->id))->toBeNull()
        ->and($message->fresh())->toBeNull();
});

it('toggles and renames from the sidebar context', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Sidebar Item',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(ConversationActions::class, [
        'context' => 'sidebar',
        'activeId' => (string) $conversation->id,
    ]);

    $component->call('togglePin', (string) $conversation->id)
        ->assertDispatched('conversations-changed')
        ->assertDispatched('conversation-pin-changed', id: (string) $conversation->id, pinned: true)
        ->assertDispatched('toast');

    expect($conversation->fresh()->pinned_at)->not->toBeNull();

    $component->call('rename', (string) $conversation->id, 'Renamed Sidebar Item')
        ->assertDispatched('conversation-rename-succeeded', scope: 'sidebar')
        ->assertDispatched('conversation-renamed', id: (string) $conversation->id, title: 'Renamed Sidebar Item')
        ->assertDispatched('toast');

    expect($conversation->fresh()->title)->toBe('Renamed Sidebar Item');
});

it('deletes the active conversation from the sidebar and redirects', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'context' => 'sidebar',
        'activeId' => (string) $conversation->id,
    ])
        ->call('delete', (string) $conversation->id)
        ->assertDispatched('close-confirm-modal', id: 'sidebar-delete')
        ->assertDispatched('conversations-changed')
        ->assertRedirect(route('conversations.create'));

    expect(Conversation::find($conversation->id))->toBeNull();
});

it('deletes a non-active sidebar conversation without redirecting', function () {
    $user = User::factory()->create();
    $active = Conversation::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'context' => 'sidebar',
        'activeId' => (string) $active->id,
    ])
        ->call('delete', (string) $conversation->id)
        ->assertDispatched('close-confirm-modal', id: 'sidebar-delete')
        ->assertDispatched('conversations-changed')
        ->assertDispatched('toast', type: 'success')
        ->assertNoRedirect();

    expect(Conversation::find($conversation->id))->toBeNull();
});

it('deletes a search result through the single delete modal without redirecting', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'context' => 'search',
    ])
        ->call('delete', (string) $conversation->id)
        ->assertDispatched('close-confirm-modal', id: 'search-item-delete')
        ->assertDispatched('conversations-changed')
        ->assertDispatched('toast', type: 'success')
        ->assertNoRedirect();

    expect(Conversation::find($conversation->id))->toBeNull();
});

it('renames a search result with the search scope and without changing the page title', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Search Item',
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'context' => 'search',
    ])
        ->call('rename', (string) $conversation->id, 'Renamed Search Item')
        ->assertDispatched('conversation-rename-succeeded', scope: 'search')
        ->assertDispatched('conversations-changed')
        ->assertNotDispatched('conversation-renamed')
        ->assertDispatched('toast');

    expect($conversation->fresh()->title)->toBe('Renamed Search Item');
});

it('rejects individual actions for another user conversation in a list context', function () {
    $user = User::factory()->create();
    $foreign = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, ['context' => 'sidebar'])
        ->call('togglePin', (string) $foreign->id)
        ->assertStatus(404);

    Livewire::test(ConversationActions::class, ['context' => 'sidebar'])
        ->call('rename', (string) $foreign->id, 'Stolen Name')
        ->assertStatus(404);
});

it('rejects deleting another user conversation from a list context', function () {
    $user = User::factory()->create();
    $foreign = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, ['context' => 'sidebar'])
        ->call('delete', (string) $foreign->id)
        ->assertDispatched('close-confirm-modal', id: 'sidebar-delete')
        ->assertDispatched('toast', type: 'error')
        ->assertNotDispatched('conversations-changed')
        ->assertNoRedirect();

    expect(Conversation::find($foreign->id))->not->toBeNull();
});

it('renders a clipboard fallback for sharing', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationActions::class, [
        'conversationId' => (string) $conversation->id,
    ])
        ->assertSee('مشاركة')
        ->assertSee('حذف المحادثة')
        ->assertSee('document.execCommand', false)
        ->assertSee('navigator.clipboard', false)
        ->assertSee('shareOpen', false)
        ->assertSee('إعادة المحاولة');
});
