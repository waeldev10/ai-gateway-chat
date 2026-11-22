<?php

use App\AI\AiService;
use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiResponse;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiException;
use App\Livewire\Chat\ConversationChat;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Message\MessageContextService;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.test/v1',
        'model' => 'configured-model',
        'timeout' => 30,
    ]);
});

function mockStreamedReply(array $chunks, ?string $title = null): void
{
    test()->mock(AiService::class, function (MockInterface $mock) use ($chunks, $title): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () use ($chunks) {
                foreach ($chunks as $chunk) {
                    yield new AiChunk($chunk);
                }
            })());

        if ($title !== null) {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(new AiResponse($title, 'openai', 'configured-model'));
        }
    });
}

function mockGeneratedReply(string $content): void
{
    test()->mock(AiService::class, function (MockInterface $mock) use ($content): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturn(new AiResponse($content, 'openai', 'configured-model'));
    });
}

it('renders an existing conversation with its messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Hello',
        'status' => MessageStatus::Complete,
    ]);
    $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Hi **there**',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSet('conversationId', (string) $conversation->id)
        ->assertSee('Hello')
        ->assertSeeHtml('<strong>there</strong>');
});

it('renders the offline notice inside the composer for new and existing conversations', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ConversationChat::class)
        ->assertSeeHtml('data-chat-offline-notice')
        ->assertSee('لا يتوفر اتصال بالإنترنت')
        ->assertSee('لن تُرسل الرسالة الآن. أعد الاتصال لإرسالها.')
        ->assertSeeHtml('bg-destructive/5')
        ->assertDontSeeHtml('providerProbeUrl')
        ->assertDontSeeHtml('setInterval(')
        ->assertDontSeeHtml('fetch(');

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSeeHtml('data-chat-offline-notice')
        ->assertSee('لا يتوفر اتصال بالإنترنت')
        ->assertSee('لن تُرسل الرسالة الآن. أعد الاتصال لإرسالها.')
        ->assertSeeHtml('bg-destructive/5')
        ->assertDontSeeHtml('providerProbeUrl')
        ->assertDontSeeHtml('setInterval(')
        ->assertDontSeeHtml('fetch(');
});

it('shows a chat-scoped connection notice when the ai provider cannot be reached', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andThrow(AiException::connectionFailed(
                'openai',
                new ConnectionException('Provider is unreachable.'),
            ));
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'Hello')
        ->call('send')
        ->assertDispatched('ai-provider-connection-failed');

    expect($conversation->messages()->where('role', MessageRole::User->value)->count())->toBe(1)
        ->and($conversation->messages()->where('role', MessageRole::Assistant->value)->count())->toBe(0);
});

it('loads conversation history in batches of twenty from newest to oldest', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    foreach (range(1, 45) as $number) {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'History message '.$number,
            'status' => MessageStatus::Complete,
            'created_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($user);

    $component = Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSet('hasOlderMessages', true)
        ->assertSee('History message 26')
        ->assertSee('History message 45')
        ->assertDontSee('History message 25')
        ->assertSeeHtml('data-older-messages-sentinel');

    $component->call('loadOlderMessages')
        ->assertSee('History message 6')
        ->assertSee('History message 25')
        ->assertDontSee('History message 5')
        ->assertSet('hasOlderMessages', true);

    $component->call('loadOlderMessages')
        ->assertSee('History message 1')
        ->assertSee('History message 45')
        ->assertSet('hasOlderMessages', false)
        ->assertDontSeeHtml('data-older-messages-sentinel');
});

it('escapes user html and sanitizes assistant markdown', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => '<script>alert(1)</script>',
        'status' => MessageStatus::Complete,
    ]);
    $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '<img src=x onerror="alert(1)"> [click](javascript:alert(1))',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img', false)
        ->assertDontSee('href="javascript:', false)
        ->assertDontSee("href='javascript:", false);
});

it('renders fenced code blocks with escaped code and copy controls', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => "```php\necho '<script>alert(1)</script>';\n```",
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSeeHtml('data-code-block="true"')
        ->assertSeeHtml('data-copy-code')
        ->assertSeeHtml('language-php')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('نسخ الكود');
});

it('marks an incomplete assistant reply', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Partial answer',
        'status' => MessageStatus::Failed,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSee('Partial answer')
        ->assertSee('رد غير مكتمل');
});

it('sends a message on an existing conversation', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);
    mockStreamedReply(['Hello', ' back']);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'Hi')
        ->call('send')
        ->assertSet('draft', '')
        ->assertHasNoErrors()
        ->assertDispatched('conversations-changed')
        ->assertDispatched('conversation-chat-updated', id: (string) $conversation->id)
        ->assertSee('Hi')
        ->assertSee('Hello back')
        ->assertNoRedirect();

    expect($conversation->messages()->where('role', MessageRole::User->value)->sole()->content)->toBe('Hi')
        ->and($conversation->messages()->where('role', MessageRole::Assistant->value)->sole()->content)->toBe('Hello back');
});

it('redirects immediately after the first message and streams it on the conversation page', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(ConversationChat::class)
        ->set('draft', 'First message')
        ->call('send');

    $conversation = Conversation::query()->sole();

    $component
        ->assertSet('conversationId', (string) $conversation->id)
        ->assertSet('isInitialSendInProgress', true)
        ->assertRedirect(route('conversations.show', $conversation));

    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->title)->toBeNull()
        ->and($conversation->messages()->count())->toBe(0)
        ->and(RateLimiter::attempts('ai-stream:'.$user->getKey()))->toBe(1);

    mockStreamedReply(['Welcome'], 'Welcome title');

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSet('shouldSendInitialMessage', true)
        ->assertSet('draft', 'First message')
        ->assertSeeHtml('data-chat-active="true"')
        ->call('sendInitialMessage')
        ->assertSet('shouldSendInitialMessage', false)
        ->assertSet('draft', '')
        ->assertSee('First message')
        ->assertSee('Welcome')
        ->assertNoRedirect();

    expect($conversation->fresh()->title)->toBe('Welcome title')
        ->and($conversation->messages()->count())->toBe(2)
        ->and(RateLimiter::attempts('ai-stream:'.$user->getKey()))->toBe(1);
});

it('names an untitled conversation after the first message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create([
        'title' => null,
    ]);

    $this->actingAs($user);
    mockStreamedReply(['Hi'], 'First words title');

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'First words')
        ->call('send')
        ->assertDispatched('conversation-renamed', id: (string) $conversation->id, title: 'First words title');

    expect($conversation->fresh()->title)->toBe('First words title');
});

it('keeps the new conversation page open and preserves the draft if its first generation fails', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ConversationChat::class)
        ->set('draft', 'Hello')
        ->call('send')
        ->assertRedirect(route('conversations.show', Conversation::query()->sole()));

    $conversation = Conversation::query()->sole();

    $this->mock(MessageService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andThrow(new RuntimeException('stream failed'));
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('sendInitialMessage')
        ->assertSet('draft', 'Hello')
        ->assertSet('conversationId', (string) $conversation->id)
        ->assertDispatched('toast', type: 'error')
        ->assertNoRedirect();

    expect($conversation->fresh())->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(0);
});

it('rejects an empty draft without creating a conversation', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ConversationChat::class)
        ->set('draft', '   ')
        ->call('send')
        ->assertHasErrors(['draft'])
        ->assertSeeHtml('id="draft-error"')
        ->assertSeeHtml('aria-describedby="draft-error"')
        ->assertNoRedirect();

    expect(Conversation::query()->count())->toBe(0);
});

it('rate limits message sends per user', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    foreach (range(1, 20) as $attempt) {
        RateLimiter::hit('ai-stream:'.$user->getKey(), 60);
    }

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'One too many')
        ->call('send')
        ->assertSet('draft', 'One too many')
        ->assertDispatched('toast', type: 'error');

    expect($conversation->messages()->count())->toBe(0);
});

it('reports a busy conversation without losing the draft', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $lock = Cache::lock('message-operation:'.$conversation->getKey(), 60);

    expect($lock->get())->toBeTrue();

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'Hello')
        ->call('send')
        ->assertSet('draft', 'Hello')
        ->assertDispatched('toast', type: 'error');

    expect($conversation->messages()->count())->toBe(0);
});

it('persists a partial reply and notifies the user when streaming fails', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('partial');

                throw new RuntimeException('stream failed');
            })());
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'Hi')
        ->call('send')
        ->assertSet('draft', '')
        ->assertDispatched('toast', type: 'error')
        ->assertSee('رد غير مكتمل');

    $assistant = $conversation->messages()->where('role', MessageRole::Assistant->value)->sole();

    expect($assistant->content)->toBe('partial')
        ->and($assistant->status)->toBe(MessageStatus::Failed);
});

it('does not mount another user conversation', function () {
    $user = User::factory()->create();
    $foreign = Conversation::factory()->for(User::factory())->create();

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $foreign->id])
        ->assertStatus(404);
});

it('renders the chat component on the conversation pages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(route('conversations.create'))
        ->assertOk()
        ->assertSeeLivewire(ConversationChat::class);

    $this->get(route('conversations.show', $conversation))
        ->assertOk()
        ->assertSeeLivewire(ConversationChat::class);
});

it('exposes raw message content for copy and share', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $content = "مرحبا بالعالم \"اقتباس\"\nسطر ثانٍ & <b>tag</b> 'single'";

    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => $content,
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $html = Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])->html();

    expect($html)->toContain('data-message-content="'.e($content).'"')
        ->and($html)->toContain('this.$el.dataset.messageContent')
        ->and($html)->not->toContain(base64_encode($content))
        ->and($html)->not->toContain('JSON.parse');
});

it('mounts the send draft and an in-place target for regeneration', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Hello',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Hi',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->assertSeeHtml('wire:stream="assistant-draft"')
        ->assertSeeHtml('wire:target="send,sendInitialMessage"')
        ->assertSeeHtml('class="flex w-full items-start gap-3" dir="rtl"')
        ->assertSeeHtml('data-assistant-draft')
        ->assertSeeHtml('wire:stream="assistant-regeneration-'.$assistant->id.'"')
        ->assertSeeHtml('data-assistant-regeneration')
        ->assertSeeHtml('data-stream-target')
        ->assertSeeHtml('wire:stream="assistant-edit-'.$assistant->id.'"')
        ->assertDontSeeHtml('data-stop');
});

it('validates edited message content', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $message = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Original message',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $message->id, '   ')
        ->assertHasErrors(['content'])
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('x-show="editing && showServerError && !editError"');

    expect($message->fresh()->content)->toBe('Original message')
        ->and($conversation->messages()->count())->toBe(1);
});

it('edits a user message and regenerates the assistant reply', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $userMessage = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Original question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);
    mockStreamedReply(['Updated ', 'answer']);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $userMessage->id, 'Updated question')
        ->assertReturned(true)
        ->assertHasNoErrors()
        ->assertDispatched('conversation-chat-updated', id: (string) $conversation->id);

    expect($userMessage->fresh()->content)->toBe('Updated question')
        ->and($assistant->fresh()->content)->toBe('Updated answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Complete)
        ->and($conversation->messages()->count())->toBe(2);

    expect(AiRequestRecord::query()->sole()->source)->toBe(AiSource::Web);
});

it('removes later messages when editing an earlier user message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $priorUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Prior question',
        'status' => MessageStatus::Complete,
    ]);
    $priorAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Prior answer',
        'status' => MessageStatus::Complete,
    ]);
    $target = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Original question',
        'status' => MessageStatus::Complete,
    ]);
    $targetAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);
    $laterAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Later answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $sentRequest = null;

    $this->mock(AiService::class, function (MockInterface $mock) use (&$sentRequest): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function ($request) use (&$sentRequest) {
                $sentRequest = $request;

                return (function () {
                    yield new AiChunk('Updated answer');
                })();
            });
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $target->id, 'Updated question')
        ->assertReturned(true)
        ->assertHasNoErrors();

    expect($target->fresh()->content)->toBe('Updated question')
        ->and($targetAssistant->fresh()->content)->toBe('Updated answer')
        ->and($priorUser->fresh()->content)->toBe('Prior question')
        ->and($priorAssistant->fresh()->content)->toBe('Prior answer')
        ->and($laterUser->fresh())->toBeNull()
        ->and($laterAssistant->fresh())->toBeNull()
        ->and($conversation->messages()->count())->toBe(4)
        ->and($sentRequest->messages)->toBe(withDefaultSystemPrompt([
            ['role' => 'user', 'content' => 'Prior question'],
            ['role' => 'assistant', 'content' => 'Prior answer'],
            ['role' => 'user', 'content' => 'Updated question'],
        ]));

    expect($conversation->messages()->orderBy('created_at')->orderBy('id')->pluck('id')->all())
        ->toBe([$priorUser->id, $priorAssistant->id, $target->id, $targetAssistant->id]);
});

it('preserves the original edit and branch when regeneration fails', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $target = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Original question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andThrow(new RuntimeException('generation failed'));
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $target->id, 'Updated question')
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($target->fresh()->content)->toBe('Original question')
        ->and($assistant->fresh()->content)->toBe('Old answer')
        ->and($laterUser->fresh())->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(3)
        ->and(AiRequestRecord::query()->sole()->status)->toBe(AiRequestStatus::Failed);
});

it('rolls back the edit when branch deletion fails', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $target = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Original question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('Updated ');
                throw new RuntimeException('delete failed');
            })());
    });

    $this->partialMock(MessageContextService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('deleteAfter')
            ->once()
            ->andThrow(new RuntimeException('delete failed'));
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $target->id, 'Updated question')
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($target->fresh()->content)->toBe('Original question')
        ->and($assistant->fresh()->content)->toBe('Old answer')
        ->and($laterUser->fresh())->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(3)
        ->and(AiRequestRecord::query()->sole()->status)->toBe(AiRequestStatus::Failed);
});

it('rejects editing an assistant message', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Assistant message',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('editMessage', $assistant->id, 'Hacked')
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($assistant->fresh()->content)->toBe('Assistant message')
        ->and($conversation->messages()->count())->toBe(1);
});

it('regenerates an assistant reply through the streaming pipeline', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $userMessage = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $sentRequest = null;

    $this->mock(AiService::class, function (MockInterface $mock) use (&$sentRequest): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function ($request) use (&$sentRequest) {
                $sentRequest = $request;

                return (function () {
                    yield new AiChunk('Fresh ');
                    yield new AiChunk('answer');
                })();
            });
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('regenerateMessage', $assistant->id)
        ->assertReturned(true)
        ->assertHasNoErrors()
        ->assertDispatched('conversation-chat-updated', id: (string) $conversation->id);

    expect($assistant->fresh()->content)->toBe('Fresh answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Complete)
        ->and($conversation->messages()->count())->toBe(2)
        ->and($sentRequest->messages)->toBe(withDefaultSystemPrompt([
            ['role' => 'user', 'content' => 'Question'],
        ]));

    expect(AiRequestRecord::query()->sole()->source)->toBe(AiSource::Web);
});

it('removes later messages when regenerating an assistant reply', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $userMessage = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'First question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);
    $laterAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Later answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $sentRequest = null;

    $this->mock(AiService::class, function (MockInterface $mock) use (&$sentRequest): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function ($request) use (&$sentRequest) {
                $sentRequest = $request;

                return (function () {
                    yield new AiChunk('Fresh answer');
                })();
            });
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('regenerateMessage', $assistant->id)
        ->assertReturned(true)
        ->assertHasNoErrors();

    expect($assistant->fresh()->content)->toBe('Fresh answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Complete)
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages()->whereKey($laterUser->id)->exists())->toBeFalse()
        ->and($conversation->messages()->whereKey($laterAssistant->id)->exists())->toBeFalse()
        ->and($sentRequest->messages)->toBe(withDefaultSystemPrompt([
            ['role' => 'user', 'content' => 'First question'],
        ]));

    expect($conversation->messages()->orderBy('created_at')->orderBy('id')->pluck('id')->all())
        ->toBe([$userMessage->id, $assistant->id]);
});

it('keeps the original reply when regeneration fails before the first chunk', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);
    $laterAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Later answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                throw new RuntimeException('stream failed');
                yield;
            })());
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('regenerateMessage', $assistant->id)
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($assistant->fresh()->content)->toBe('Old answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Complete)
        ->and($conversation->messages()->count())->toBe(4)
        ->and($laterUser->fresh())->not->toBeNull()
        ->and($laterAssistant->fresh())->not->toBeNull();

    $aiRequest = AiRequestRecord::query()->sole();

    expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
        ->and($aiRequest->message_id)->toBeNull();
});

it('persists partial regenerated content as failed', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);
    $laterAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Later answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('Partial answer');

                throw new RuntimeException('stream failed');
            })());
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('regenerateMessage', $assistant->id)
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($assistant->fresh()->content)->toBe('Partial answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($conversation->messages()->count())->toBe(2)
        ->and($laterUser->fresh())->toBeNull()
        ->and($laterAssistant->fresh())->toBeNull();

    $aiRequest = AiRequestRecord::query()->sole();

    expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
        ->and($aiRequest->message_id)->toBe($assistant->id);
});

it('rolls back the branch when persistence cannot delete later messages', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Question',
        'status' => MessageStatus::Complete,
    ]);
    $assistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Old answer',
        'status' => MessageStatus::Complete,
    ]);
    $laterUser = $conversation->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Later question',
        'status' => MessageStatus::Complete,
    ]);
    $laterAssistant = $conversation->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Later answer',
        'status' => MessageStatus::Complete,
    ]);

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('Fresh answer');
            })());
    });

    $this->partialMock(MessageContextService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('deleteAfter')
            ->twice()
            ->andThrow(new RuntimeException('delete failed'));
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->call('regenerateMessage', $assistant->id)
        ->assertReturned(false)
        ->assertDispatched('toast', type: 'error');

    expect($assistant->fresh()->content)->toBe('Old answer')
        ->and($assistant->fresh()->status)->toBe(MessageStatus::Complete)
        ->and($conversation->messages()->count())->toBe(4)
        ->and($laterUser->fresh())->not->toBeNull()
        ->and($laterAssistant->fresh())->not->toBeNull()
        ->and(AiRequestRecord::query()->sole()->status)->toBe(AiRequestStatus::Failed);
});

it('notifies the user when the provider fails before the first chunk', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    $this->actingAs($user);

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                throw new RuntimeException('stream failed');
                yield;
            })());
    });

    Livewire::test(ConversationChat::class, ['conversationId' => (string) $conversation->id])
        ->set('draft', 'Hi')
        ->call('send')
        ->assertSet('draft', '')
        ->assertDispatched('toast', type: 'error');

    expect($conversation->messages()->where('role', MessageRole::User->value)->count())->toBe(1)
        ->and($conversation->messages()->where('role', MessageRole::Assistant->value)->count())->toBe(0);
});
