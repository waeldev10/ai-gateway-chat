<?php

use App\Models\User;

it('redirects guests from the root to the login page', function () {
    $this->get('/')
        ->assertRedirect(route('login'));
});

it('redirects authenticated users from the root to a new conversation', function () {
    $this->actingAs(User::factory()->make())
        ->get('/')
        ->assertRedirect(route('conversations.create'));
});
