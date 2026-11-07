<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Exceptions\AiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.default', 'anthropic');
    config()->set('ai.connections.anthropic', [
        'driver' => 'anthropic-messages',
        'api_key' => 'anthropic-key',
        'base_url' => 'https://api.anthropic.com/v1',
        'model' => 'claude-test',
        'timeout' => 30,
        'max_tokens' => 512,
        'version' => '2023-06-01',
    ]);

    Http::preventStrayRequests();
});

it('generates text from the messages protocol and normalizes the response', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'claude-test-2026',
            'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'Claude'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ]),
    ]);

    $response = app(AiService::class)->generate(AiRequest::fromMessages([
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi'],
        ['role' => 'user', 'content' => 'How are you?'],
    ]));

    expect($response->content)->toBe('Hello Claude')
        ->and($response->connection)->toBe('anthropic')
        ->and($response->model)->toBe('claude-test-2026')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->usage?->promptTokens)->toBe(10)
        ->and($response->usage?->completionTokens)->toBe(4)
        ->and($response->usage?->totalTokens)->toBe(14);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'anthropic-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-test'
            && $request['max_tokens'] === 512
            && $request['system'] === 'Be concise.'
            && $request['messages'] === [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi'],
                ['role' => 'user', 'content' => 'How are you?'],
            ];
    });
});

it('streams text deltas and the completion metadata', function () {
    $body = implode("\n\n", [
        'event: message_start',
        'data: '.json_encode([
            'type' => 'message_start',
            'message' => [
                'model' => 'claude-test-2026',
                'usage' => ['input_tokens' => 11, 'output_tokens' => 1],
            ],
        ]),
        'event: content_block_start',
        'data: '.json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
        'event: ping',
        'data: '.json_encode(['type' => 'ping']),
        'event: content_block_delta',
        'data: '.json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]),
        'event: content_block_delta',
        'data: '.json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '!']]),
        'event: content_block_stop',
        'data: '.json_encode(['type' => 'content_block_stop', 'index' => 0]),
        'event: message_delta',
        'data: '.json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'max_tokens'],
            'usage' => ['output_tokens' => 9],
        ]),
        'event: message_stop',
        'data: '.json_encode(['type' => 'message_stop']),
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->content)->toBe('Hello')
        ->and($chunks[1]->content)->toBe('!')
        ->and($chunks[2]->content)->toBe('')
        ->and($chunks[2]->finishReason)->toBe('length')
        ->and($chunks[2]->usage?->promptTokens)->toBe(11)
        ->and($chunks[2]->usage?->completionTokens)->toBe(9)
        ->and($chunks[2]->usage?->totalTokens)->toBe(20);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true);
});

it('throws when the stream contains an error event', function () {
    Http::fake([
        '*' => Http::response(
            'event: error'."\n".'data: '.json_encode(['type' => 'error', 'error' => ['message' => 'Provider failure']])."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    expect(fn () => iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]))))->toThrow(AiException::class, 'AI connection [anthropic] streaming failed.');
});

it('throws a safe error for authentication failures', function () {
    Http::fake([
        '*' => Http::response(['type' => 'error', 'error' => ['message' => 'Invalid API key']], 401),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [anthropic] rejected the configured credentials.');
});

it('throws when the response format is unexpected', function () {
    Http::fake([
        '*' => Http::response(['content' => [], 'stop_reason' => null]),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [anthropic] returned an unexpected response.');
});
