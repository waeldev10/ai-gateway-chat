<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires authentication for the conversation pages', function () {
    $conversation = Conversation::factory()->create();

    $this->get(route('conversations.create'))
        ->assertRedirect(route('login'));

    $this->get(route('conversations.show', $conversation))
        ->assertRedirect(route('login'));
});

it('renders the conversation landing page with the sidebar list', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('conversations.create'))
        ->assertOk()
        ->assertSee('Laravel AI Hub')
        ->assertSeeLivewire('sidebar.sidebar-conversations');
});

it('renders a conversation page with its title and actions', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => 'Quarterly Planning',
    ]);

    $this->actingAs($user);

    $this->get(route('conversations.show', $conversation))
        ->assertOk()
        ->assertSee('Quarterly Planning')
        ->assertSeeLivewire('conversation.conversation-actions')
        ->assertSee(route('conversations.show', $conversation));
});

it('forbids viewing another user conversation', function () {
    $user = User::factory()->create();
    $foreign = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($user);

    $this->get(route('conversations.show', $foreign))
        ->assertForbidden();
});

it('returns not found for an unknown conversation', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('conversations.show', (string) Str::ulid()))
        ->assertNotFound();
});
