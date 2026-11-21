<?php

use App\Livewire\Conversation\ConversationSearch;
use App\Livewire\Sidebar\SidebarConversations;
use App\Models\Conversation;
use App\Models\User;
use App\Support\ArabicDateTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('formats relative conversation timestamps in Arabic', function () {
    $now = Carbon::parse('2026-01-10 12:00:00');

    expect(ArabicDateTime::forConversation($now->copy()->subSeconds(20), $now))->toBe('الآن')
        ->and(ArabicDateTime::forConversation($now->copy()->subMinute(), $now))->toBe('منذ دقيقة')
        ->and(ArabicDateTime::forConversation($now->copy()->subMinutes(2), $now))->toBe('منذ دقيقتين')
        ->and(ArabicDateTime::forConversation($now->copy()->subMinutes(5), $now))->toBe('منذ ٥ دقائق')
        ->and(ArabicDateTime::forConversation($now->copy()->subHour(), $now))->toBe('منذ ساعة')
        ->and(ArabicDateTime::forConversation($now->copy()->subHours(2), $now))->toBe('منذ ساعتين')
        ->and(ArabicDateTime::forConversation($now->copy()->subHours(5), $now))->toBe('منذ ٥ ساعات')
        ->and(ArabicDateTime::forConversation($now->copy()->subDay(), $now))->toBe('أمس')
        ->and(ArabicDateTime::forConversation($now->copy()->subDays(3), $now))->toBe('منذ ٣ أيام');
});

it('falls back to an Arabic date for older conversations', function () {
    $now = Carbon::parse('2026-01-10 12:00:00');
    $value = ArabicDateTime::forConversation(Carbon::parse('2025-03-09 08:00:00'), $now);

    expect($value)->toMatch('/[٠-٩]/u');
});

it('renders relative timestamps only in search results', function () {
    $this->travelTo(Carbon::parse('2026-01-10 12:00:00'));

    $user = User::factory()->create();

    Conversation::factory()->for($user)->create([
        'title' => 'Fresh Conversation',
        'updated_at' => now()->subMinutes(5),
    ]);
    Conversation::factory()->for($user)->create([
        'title' => 'Older Conversation',
        'updated_at' => now()->subDays(3),
    ]);

    $this->actingAs($user);

    Livewire::test(SidebarConversations::class)
        ->assertSee('Fresh Conversation')
        ->assertDontSee('منذ ٥ دقائق')
        ->assertSee('Older Conversation')
        ->assertDontSee('منذ ٣ أيام');

    Livewire::test(ConversationSearch::class)
        ->assertSee('Fresh Conversation')
        ->assertSee('منذ ٥ دقائق')
        ->assertSee('Older Conversation')
        ->assertSee('منذ ٣ أيام');
});
