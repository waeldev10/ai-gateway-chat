<?php

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// describe('completed history', function () {
//     it('returns completed messages before the target in chronological order', function () {
//         $conversation = Conversation::factory()->create();
//         $otherConversation = Conversation::factory()->create();
//         $sameTime = now()->subMinute();

//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::User,
//             'content' => 'First question',
//             'status' => MessageStatus::Complete,
//             'created_at' => now()->subMinutes(2),
//         ]);
//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::User,
//             'content' => 'Ignored failed question',
//             'status' => MessageStatus::Failed,
//             'created_at' => $sameTime,
//         ]);
//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::Assistant,
//             'content' => 'First answer',
//             'status' => MessageStatus::Complete,
//             'created_at' => $sameTime,
//         ]);
//         $target = Message::factory()->for($conversation)->create([
//             'role' => MessageRole::User,
//             'content' => 'Target question',
//             'status' => MessageStatus::Complete,
//             'created_at' => now(),
//         ]);
//         Message::factory()->for($otherConversation)->create([
//             'role' => MessageRole::Assistant,
//             'content' => 'Other conversation answer',
//             'status' => MessageStatus::Complete,
//             'created_at' => now()->subMinutes(2),
//         ]);

//         $history = app(MessageContextService::class)->completedHistory($conversation, $target);

//         expect($history)->toBe([
//             ['role' => 'user', 'content' => 'First question'],
//             ['role' => 'assistant', 'content' => 'First answer'],
//         ]);
//     });

//     it('selects only the columns required by the ai history', function () {
//         $conversation = Conversation::factory()->create();

//         Message::factory()->for($conversation)->create([
//             'role' => MessageRole::User,
//             'content' => 'Question',
//             'status' => MessageStatus::Complete,
//         ]);

//         $queries = [];

//         DB::listen(function ($query) use (&$queries): void {
//             $queries[] = $query->sql;
//         });

//         app(MessageContextService::class)->completedHistory($conversation);

//         $historyQuery = collect($queries)->first(
//             fn (string $sql): bool => str_contains($sql, 'from "messages"'),
//         );

//         expect($historyQuery)->not->toBeNull()
//             ->and($historyQuery)->toContain('"role", "content"')
//             ->and($historyQuery)->not->toContain('*');
//     });
// });

// describe('next message lookup', function () {
//     it('returns the next message scoped to the conversation', function () {
//         $conversation = Conversation::factory()->create();
//         $otherConversation = Conversation::factory()->create();
//         $sameTime = now()->subMinute();

//         $first = Message::factory()->for($conversation)->create([
//             'created_at' => now()->subMinutes(2),
//         ]);
//         $second = Message::factory()->for($conversation)->create([
//             'created_at' => $sameTime,
//         ]);
//         $third = Message::factory()->for($conversation)->create([
//             'created_at' => $sameTime,
//         ]);
//         Message::factory()->for($otherConversation)->create([
//             'created_at' => now(),
//         ]);

//         $service = app(MessageContextService::class);

//         expect($service->nextMessageAfter($conversation, $first)?->is($second))->toBeTrue()
//             ->and($service->nextMessageAfter($conversation, $second)?->is($third))->toBeTrue()
//             ->and($service->nextMessageAfter($conversation, $third))->toBeNull();
//     });
// });

it('limits context to the latest configured messages', function () {
    config([
        'ai.context.max_messages' => 3,
        'ai.context.max_characters' => 24000,
    ]);

    $conversation = Conversation::factory()->create();

    foreach (['one', 'two', 'three', 'four', 'five'] as $content) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
            'status' => MessageStatus::Complete,
        ]);
    }

    $history = app(MessageContextService::class)
        ->completedHistory($conversation);

    expect(array_column($history, 'content'))
        ->toBe(['three', 'four', 'five']);
});

it('limits context by characters while preserving recent messages', function () {
    config([
        'ai.context.max_messages' => 25,
        'ai.context.max_characters' => 9,
    ]);

    $conversation = Conversation::factory()->create();

    foreach (['1234', '5678', '90'] as $content) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
            'status' => MessageStatus::Complete,
        ]);
    }

    $history = app(MessageContextService::class)
        ->completedHistory($conversation);

    expect(array_column($history, 'content'))
        ->toBe(['5678', '90']);
});

it('appends the current user message within context limits', function () {
    config([
        'ai.context.max_messages' => 3,
        'ai.context.max_characters' => 20,
    ]);

    $conversation = Conversation::factory()->create();

    foreach (['old', 'middle', 'latest'] as $content) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
            'status' => MessageStatus::Complete,
        ]);
    }

    $history = app(MessageContextService::class)
        ->completedHistory($conversation, null, [
            'role' => MessageRole::User->value,
            'content' => 'current',
        ]);

    expect(array_column($history, 'content'))
        ->toBe(['middle', 'latest', 'current']);
});
