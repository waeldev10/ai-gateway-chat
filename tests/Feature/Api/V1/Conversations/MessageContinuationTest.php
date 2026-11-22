<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not expose a continuation endpoint', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages/continue")
        ->assertMethodNotAllowed();
});
