<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Exceptions\AiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-api-key',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    Http::preventStrayRequests();
});

it('generates text and normalizes the response', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'test-model-2026',
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Hello there'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 3,
                'total_tokens' => 15,
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(AiRequest::fromMessages([
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->content)->toBe('Hello there')
        ->and($response->connection)->toBe('openai')
        ->and($response->model)->toBe('test-model-2026')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->usage?->promptTokens)->toBe(12)
        ->and($response->usage?->completionTokens)->toBe(3)
        ->and($response->usage?->totalTokens)->toBe(15);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['model'] === 'test-model'
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'Be concise.'
            && $request['messages'][1]['role'] === 'user'
            && $request['messages'][1]['content'] === 'Hello';
    });
});

it('normalizes provider finish reasons', function () {
    Http::fake([
        '*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Truncated'], 'finish_reason' => 'length'],
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->finishReason)->toBe('length');
});

it('streams text chunks, usage, and the completion metadata', function () {
    $body = implode("\n\n", [
        'data: '.json_encode(['choices' => [['delta' => ['role' => 'assistant'], 'finish_reason' => null]]]),
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]]),
        'data: '.json_encode(['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]]]),
        'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]),
        'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12]]),
        'data: [DONE]',
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->content)->toBe('Hello')
        ->and($chunks[1]->content)->toBe(' world')
        ->and($chunks[2]->content)->toBe('')
        ->and($chunks[2]->finishReason)->toBe('stop')
        ->and($chunks[2]->usage?->promptTokens)->toBe(10)
        ->and($chunks[2]->usage?->completionTokens)->toBe(2)
        ->and($chunks[2]->usage?->totalTokens)->toBe(12);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true
        && $request['stream_options']['include_usage'] === true);
});

it('parses CRLF events and multiline data fields', function () {
    $body = implode("\r\n", [
        'data: {"choices":',
        'data: [{"delta":{"content":"Hello"},"finish_reason":"stop"}]}',
        '',
        'event: done',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Hello')
        ->and($chunks[1]->finishReason)->toBe('stop');
});

it('skips malformed stream events', function () {
    $body = implode("\n\n", [
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'first']]]]),
        'data: not-json',
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'second']]]]),
        'data: [DONE]',
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('first')
        ->and($chunks[1]->content)->toBe('second');
});

it('throws when the stream contains a provider error and preserves its details', function () {
    Http::fake([
        '*' => Http::response(
            'data: '.json_encode(['error' => ['type' => 'server_error', 'message' => 'Provider failure']])."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $exception = null;

    try {
        iterator_to_array(app(AiService::class)->stream(new AiRequest([
            ['role' => 'user', 'content' => 'Hello'],
        ])));
    } catch (AiException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toBe('AI connection [openai] streaming failed.')
        ->and($exception->providerCode)->toBe('server_error')
        ->and($exception->providerMessage)->toBe('Provider failure');
});

it('throws a safe error for authentication failures', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] rejected the configured credentials.');
});

it('throws a safe error for rate limit failures', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Too many requests']], 429),
    ]);

    expect(fn () => iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]))))->toThrow(AiException::class, 'AI connection [openai] has exceeded its rate limit.');
});

it('throws a safe error for provider failures', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Internal provider failure']], 500),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] request failed with HTTP status [500].');
});

it('throws a safe error when the provider is unreachable', function () {
    Http::fake([
        '*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] could not reach the provider.');
});

it('throws when the response format is unexpected', function () {
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' => null]]]]),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] returned an unexpected response.');
});

it('logs provider failures without leaking credentials', function () {
    Log::spy();

    Http::fake([
        '*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Bad request']], 400),
    ]);

    try {
        app(AiService::class)->generate(new AiRequest([
            ['role' => 'user', 'content' => 'Hello'],
        ]));
    } catch (AiException) {
    }

    Log::shouldHaveReceived('log')->withArgs(function (string $level, string $message, array $context): bool {
        return $level === 'error'
            && $context['connection'] === 'openai'
            && $context['provider_code'] === 'invalid_request_error'
            && $context['provider_message'] === 'Bad request'
            && ! str_contains(json_encode($context), 'test-api-key');
    });
});
