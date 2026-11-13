<?php

use App\AI\AiService;
use App\AI\DTOs\AiResponse;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Message\MessageGenerationService;
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

it('marks the ai request failed and rethrows the persistence exception', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();
    $persistenceException = new RuntimeException('Unable to persist the assistant message.');

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturn(new AiResponse('Generated answer', 'openai', 'gpt-test'));
    });

    Message::creating(function () use ($persistenceException): void {
        throw $persistenceException;
    });

    $caught = null;

    try {
        app(MessageGenerationService::class)->generate($conversation, [
            ['role' => 'user', 'content' => 'Question'],
        ]);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($persistenceException)
        ->and($conversation->messages()->count())->toBe(0);

    $aiRequest = AiRequestRecord::query()->sole();

    expect($aiRequest->status)->toBe(AiRequestStatus::Failed)
        ->and($aiRequest->message_id)->toBeNull()
        ->and($aiRequest->error_message)->toBe($persistenceException->getMessage());
});

it('preserves the persistence exception when marking the ai request failed also fails', function () {
    $conversation = Conversation::factory()->for(User::factory())->create();
    $persistenceException = new RuntimeException('Unable to persist the assistant message.');

    $this->mock(AiService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('generate')
            ->once()
            ->andReturn(new AiResponse('Generated answer', 'openai', 'gpt-test'));
    });

    Message::creating(function () use ($persistenceException): void {
        throw $persistenceException;
    });

    AiRequestRecord::updating(function (AiRequestRecord $request): void {
        if ($request->status === AiRequestStatus::Failed) {
            throw new RuntimeException('Unable to mark the AI request as failed.');
        }
    });

    $caught = null;

    try {
        app(MessageGenerationService::class)->generate($conversation, [
            ['role' => 'user', 'content' => 'Question'],
        ]);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($persistenceException)
        ->and($conversation->messages()->count())->toBe(0)
        ->and(AiRequestRecord::query()->sole()->status)->toBe(AiRequestStatus::Started);
});
