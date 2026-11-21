<?php

use App\AI\AiService;
use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\ConversationBusyException;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Message\MessageRegenerationService;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('streams deltas and persists the completed exchange', function () {
    $conversation = Conversation::factory()->for(User::factory())->create([
        'title' => null,
    ]);
    $sentRequest = null;

    $this->mock(AiService::class, function (MockInterface $mock) use (&$sentRequest): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function ($request) use (&$sentRequest) {
                $sentRequest = $request;

                return (function () {
                    yield new AiChunk('مرح');
                    yield new AiChunk('با');
                    yield new AiChunk('', finishReason: 'stop', usage: new AiUsage(4, 2, 6));
                })();
            });

        $mock->shouldReceive('generate')
            ->once()
            ->andReturn(new AiResponse('عنوان المحادثة', 'openai', 'configured-model'));
    });

    $stream = app(MessageService::class)->stream($conversation, 'Hello there', AiSource::Web);

    $deltas = iterator_to_array($stream);
    $result = $stream->getReturn();

    expect($deltas)->toBe(['مرح', 'با'])
        ->and($result['failed'])->toBeFalse()
        ->and($result['user_message']->role)->toBe(MessageRole::User)
        ->and($result['user_message']->content)->toBe('Hello there')
        ->and($result['assistant_message']->role)->toBe(MessageRole::Assistant)
        ->and($result['assistant_message']->content)->toBe('مرحبا')
        ->and($result['assistant_message']->status)->toBe(MessageStatus::Complete)
        ->and($conversation->fresh()->title)->toBe('عنوان المحادثة');

    $aiRequest = AiRequestRecord::query()->whereNotNull('message_id')->sole();

    expect($aiRequest->status)->toBe(AiRequestStatus::Completed)
        ->and($aiRequest->source)->toBe(AiSource::Web)
        ->and($aiRequest->message_id)->toBe($result['assistant_message']->id)
        ->and($aiRequest->input_tokens)->toBe(4)
        ->and($aiRequest->output_tokens)->toBe(2)
        ->and($aiRequest->total_tokens)->toBe(6);

    expect(AiRequestRecord::query()->whereNull('message_id')->exists())->toBeTrue();

    expect($sentRequest->messages)->toBe(withDefaultSystemPrompt([
        ['role' => 'user', 'content' => 'Hello there'],
    ]));
});

it('persists partial output as a failed message when the stream errors', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('partial');

                throw new RuntimeException('stream failed');
            })());
    });

    $stream = app(MessageService::class)->stream($conversation, 'Question');

    $deltas = iterator_to_array($stream);
    $result = $stream->getReturn();

    expect($deltas)->toBe(['partial'])
        ->and($result['failed'])->toBeTrue()
        ->and($result['assistant_message'])->not->toBeNull()
        ->and($result['assistant_message']->content)->toBe('partial')
        ->and($result['assistant_message']->status)->toBe(MessageStatus::Failed);

    $aiRequest = AiRequestRecord::query()->sole();

    expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
        ->and($aiRequest->message_id)->toBe($result['assistant_message']->id)
        ->and($aiRequest->error_message)->toBe('stream failed');

    expect($conversation->messages()->where('role', MessageRole::Assistant->value)->count())->toBe(1);
});

it('fails without an assistant message when the stream returns no content', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->once()
            ->andReturn((function () {
                yield new AiChunk('');
                yield new AiChunk('', finishReason: 'stop');
            })());
    });

    $stream = app(MessageService::class)->stream($conversation, 'Question');

    $deltas = iterator_to_array($stream);
    $result = $stream->getReturn();

    expect($deltas)->toBe([])
        ->and($result['failed'])->toBeTrue()
        ->and($result['assistant_message'])->toBeNull()
        ->and($conversation->messages()->where('role', MessageRole::Assistant->value)->exists())->toBeFalse();

    expect(AiRequestRecord::query()->sole()->status)->toBe(AiRequestStatus::Failed);
});

it('throws while another operation holds the conversation lock', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();
    $lock = Cache::lock('message-operation:'.$conversation->getKey(), 60);

    expect($lock->get())->toBeTrue();

    $stream = app(MessageService::class)->stream($conversation, 'Hello');

    expect(fn () => $stream->current())->toThrow(ConversationBusyException::class);

    expect($conversation->messages()->count())->toBe(0)
        ->and(AiRequestRecord::query()->count())->toBe(0);
});

it('releases the conversation lock after streaming finishes', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->twice()
            ->andReturnUsing(fn () => (function () {
                yield new AiChunk('ok');
            })());
    });

    foreach ([1, 2] as $attempt) {
        $stream = app(MessageService::class)->stream($conversation, 'Message '.$attempt);
        iterator_to_array($stream);
    }

    expect($conversation->messages()->count())->toBe(4);
});

it('releases the conversation lock after regeneration streaming finishes', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();
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

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('stream')
            ->twice()
            ->andReturnUsing(fn () => (function () {
                yield new AiChunk('ok');
            })());
    });

    foreach ([1, 2] as $attempt) {
        $stream = app(MessageRegenerationService::class)->stream($conversation, $assistant);
        iterator_to_array($stream);
    }

    expect($assistant->fresh()->content)->toBe('ok');
});

it('throws while another operation holds the conversation lock during regeneration', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();
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

    $lock = Cache::lock('message-operation:'.$conversation->getKey(), 60);

    expect($lock->get())->toBeTrue();

    $stream = app(MessageRegenerationService::class)->stream($conversation, $assistant);

    expect(fn () => $stream->current())->toThrow(ConversationBusyException::class);

    expect($assistant->fresh()->content)->toBe('Old answer');
});
