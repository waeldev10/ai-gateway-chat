<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the latest messages in chronological order', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = Conversation::factory()
        ->for($user)
        ->create();

    foreach (range(1, 5) as $number) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => "Message {$number}",
            'status' => MessageStatus::Complete,
        ]);
    }

    $response = $this->getJson(
        route('api.v1.conversations.messages', [
            'conversation' => $conversation,
            'limit' => 3,
        ])
    );

    $response->assertOk();

    $contents = collect($response->json('data'))
        ->pluck('content')
        ->all();

    expect($contents)->toBe([
        'Message 3',
        'Message 4',
        'Message 5',
    ]);
});

it('navigates cursor pages without duplicate or missing messages', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = Conversation::factory()
        ->for($user)
        ->create();

    foreach (range(1, 5) as $number) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => "Message {$number}",
            'status' => MessageStatus::Complete,
            'created_at' => now()->addSeconds($number),
        ]);
    }

    $first = $this->getJson(route('api.v1.conversations.messages', [
        'conversation' => $conversation,
        'limit' => 2,
    ]))->assertOk();

    $firstContents = collect($first->json('data'))->pluck('content')->all();
    $nextCursor = $first->json('meta.next_cursor');

    expect($firstContents)->toBe(['Message 4', 'Message 5'])
        ->and($nextCursor)->not->toBeNull()
        ->and($first->json('links.next'))->toContain('limit=2');

    $second = $this->getJson(
        route('api.v1.conversations.messages', [
            'conversation' => $conversation,
            'limit' => 2,
        ]).'&cursor='.urlencode($nextCursor)
    )->assertOk();

    $secondContents = collect($second->json('data'))->pluck('content')->all();
    $nextCursor = $second->json('meta.next_cursor');

    expect($secondContents)->toBe(['Message 2', 'Message 3'])
        ->and($nextCursor)->not->toBeNull();

    $third = $this->getJson(
        route('api.v1.conversations.messages', [
            'conversation' => $conversation,
            'limit' => 2,
        ]).'&cursor='.urlencode($nextCursor)
    )->assertOk();

    $thirdContents = collect($third->json('data'))->pluck('content')->all();

    expect($thirdContents)->toBe(['Message 1'])
        ->and($third->json('meta.next_cursor'))->toBeNull()
        ->and([...$firstContents, ...$secondContents, ...$thirdContents])
        ->toHaveCount(5)
        ->and(array_unique([...$firstContents, ...$secondContents, ...$thirdContents]))
        ->toHaveCount(5);
});

it('defaults to 25 messages per page', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = Conversation::factory()
        ->for($user)
        ->create();

    foreach (range(1, 30) as $number) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => "Message {$number}",
            'status' => MessageStatus::Complete,
            'created_at' => now()->addSeconds($number),
        ]);
    }

    $response = $this->getJson(
        route('api.v1.conversations.messages', ['conversation' => $conversation])
    )->assertOk();

    $contents = collect($response->json('data'))->pluck('content')->all();

    expect($contents)->toHaveCount(25)
        ->and($contents[0])->toBe('Message 6')
        ->and($contents[24])->toBe('Message 30');
});

it('rejects page sizes outside the allowed range', function (int $limit) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = Conversation::factory()
        ->for($user)
        ->create();

    $this->getJson(
        route('api.v1.conversations.messages', [
            'conversation' => $conversation,
            'limit' => $limit,
        ])
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');
})->with([0, 101]);

it('forbids listing messages of another user conversation', function () {
    $owner = User::factory()->create();
    $conversation = Conversation::factory()->for($owner)->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson(
        route('api.v1.conversations.messages', ['conversation' => $conversation])
    )
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});
