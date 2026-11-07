<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Exceptions\AiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.default', 'openai-responses');
    config()->set('ai.connections.openai-responses', [
        'driver' => 'responses',
        'api_key' => 'responses-key',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'responses-model',
        'timeout' => 30,
    ]);

    Http::preventStrayRequests();
});

it('generates text from the responses protocol and normalizes the response', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'responses-model-2026',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Hello '],
                        ['type' => 'output_text', 'text' => 'there'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 3,
                'total_tokens' => 15,
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(AiRequest::fromMessages([
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->content)->toBe('Hello there')
        ->and($response->connection)->toBe('openai-responses')
        ->and($response->model)->toBe('responses-model-2026')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->usage?->promptTokens)->toBe(12)
        ->and($response->usage?->completionTokens)->toBe(3)
        ->and($response->usage?->totalTokens)->toBe(15);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer responses-key')
            && $request['model'] === 'responses-model'
            && $request['store'] === false
            && $request['input'][0]['role'] === 'system'
            && $request['input'][0]['content'] === 'Be concise.'
            && $request['input'][1]['role'] === 'user'
            && $request['input'][1]['content'] === 'Hello'
            && ! isset($request['stream']);
    });
});

it('normalizes incomplete responses', function () {
    Http::fake([
        '*' => Http::response([
            'model' => 'responses-model-2026',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Truncated'],
                    ],
                ],
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->finishReason)->toBe('length');
});

it('streams text deltas and the completion metadata', function () {
    $body = implode("\n\n", [
        'event: response.created',
        'data: '.json_encode(['type' => 'response.created', 'response' => ['status' => 'in_progress']]),
        'event: response.output_text.delta',
        'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hello']),
        'event: response.output_text.delta',
        'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => ' world']),
        'event: response.completed',
        'data: '.json_encode([
            'type' => 'response.completed',
            'response' => [
                'status' => 'completed',
                'model' => 'responses-model-2026',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7],
            ],
        ]),
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
        ->and($chunks[2]->usage?->promptTokens)->toBe(5)
        ->and($chunks[2]->usage?->completionTokens)->toBe(2)
        ->and($chunks[2]->usage?->totalTokens)->toBe(7);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true
        && $request['store'] === false);
});

it('streams incomplete responses with a finish reason', function () {
    $body = implode("\n\n", [
        'event: response.output_text.delta',
        'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Partial']),
        'event: response.incomplete',
        'data: '.json_encode([
            'type' => 'response.incomplete',
            'response' => [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'usage' => ['input_tokens' => 4, 'output_tokens' => 1, 'total_tokens' => 5],
            ],
        ]),
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->content)->toBe('Partial')
        ->and($chunks[1]->finishReason)->toBe('length');
});

it('throws when the stream contains an error event', function () {
    Http::fake([
        '*' => Http::response(
            'event: error'."\n".'data: '.json_encode([
                'type' => 'error',
                'code' => 'server_error',
                'message' => 'Provider failure',
            ])."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    expect(fn () => iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]))))->toThrow(AiException::class, 'AI connection [openai-responses] streaming failed.');
});

it('throws when the stream reports a failed response', function () {
    Http::fake([
        '*' => Http::response(
            'event: response.failed'."\n".'data: '.json_encode([
                'type' => 'response.failed',
                'response' => [
                    'status' => 'failed',
                    'error' => ['code' => 'server_error', 'message' => 'Provider failure'],
                ],
            ])."\n\n",
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
        ->and($exception->getMessage())->toBe('AI connection [openai-responses] returned a provider error.')
        ->and($exception->providerCode)->toBe('server_error')
        ->and($exception->providerMessage)->toBe('Provider failure');
});

it('throws a safe error for authentication failures', function () {
    Http::fake([
        '*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai-responses] rejected the configured credentials.');
});

it('throws when the response format is unexpected', function () {
    Http::fake([
        '*' => Http::response(['status' => 'completed', 'output' => []]),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai-responses] returned an unexpected response.');
});
