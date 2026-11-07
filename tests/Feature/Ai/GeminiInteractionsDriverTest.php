<?php

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Exceptions\AiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.default', 'gemini');
    config()->set('ai.connections.gemini', [
        'driver' => 'gemini-interactions',
        'api_key' => 'gemini-key',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'model' => 'gemini-test',
        'timeout' => 30,
    ]);

    Http::preventStrayRequests();
});

it('generates text through the interactions protocol and normalizes the response', function () {
    Http::fake([
        '*' => Http::response([
            'id' => 'int_123',
            'model' => 'gemini-test-001',
            'status' => 'completed',
            'steps' => [
                [
                    'type' => 'user_input',
                    'status' => 'done',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                ],
                [
                    'type' => 'model_output',
                    'status' => 'done',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        ['type' => 'text', 'text' => 'Gemini'],
                    ],
                ],
            ],
            'usage' => [
                'total_input_tokens' => 8,
                'total_output_tokens' => 2,
                'total_tokens' => 10,
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(AiRequest::fromMessages([
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi'],
        ['role' => 'user', 'content' => 'How are you?'],
    ]));

    expect($response->content)->toBe('Hello Gemini')
        ->and($response->connection)->toBe('gemini')
        ->and($response->model)->toBe('gemini-test-001')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->usage?->promptTokens)->toBe(8)
        ->and($response->usage?->completionTokens)->toBe(2)
        ->and($response->usage?->totalTokens)->toBe(10);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request->hasHeader('x-goog-api-key', 'gemini-key')
            && $request['model'] === 'gemini-test'
            && $request['store'] === false
            && $request['system_instruction'] === 'Be concise.'
            && $request['input'] === [
                [
                    'type' => 'user_input',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                ],
                [
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'Hi']],
                ],
                [
                    'type' => 'user_input',
                    'content' => [['type' => 'text', 'text' => 'How are you?']],
                ],
            ]
            && ! isset($request['stream']);
    });
});

it('normalizes incomplete and continuation statuses', function (string $status, string $expected) {
    Http::fake([
        '*' => Http::response([
            'model' => 'gemini-test-001',
            'status' => $status,
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'Partial']],
                ],
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->finishReason)->toBe($expected);
})->with([
    ['incomplete', 'length'],
    ['requires_continuation', 'length'],
    ['requires_action', 'tool_use'],
]);

it('streams text chunks and the interaction completion metadata', function () {
    $body = implode("\n\n", [
        'event: interaction.created',
        'data: '.json_encode(['interaction' => ['id' => 'int_1', 'status' => 'in_progress'], 'event_type' => 'interaction.created']),
        'event: step.start',
        'data: '.json_encode(['index' => 0, 'step' => ['type' => 'model_output'], 'event_type' => 'step.start']),
        'event: step.delta',
        'data: '.json_encode(['index' => 0, 'delta' => ['type' => 'text', 'text' => 'Hel'], 'event_type' => 'step.delta']),
        'event: step.delta',
        'data: '.json_encode(['index' => 0, 'delta' => ['type' => 'text', 'text' => 'lo'], 'event_type' => 'step.delta']),
        'event: step.stop',
        'data: '.json_encode(['index' => 0, 'event_type' => 'step.stop']),
        'event: interaction.completed',
        'data: '.json_encode([
            'interaction' => [
                'id' => 'int_1',
                'status' => 'completed',
                'usage' => [
                    'total_input_tokens' => 6,
                    'total_output_tokens' => 1,
                    'total_tokens' => 7,
                ],
            ],
            'event_type' => 'interaction.completed',
        ]),
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->content)->toBe('Hel')
        ->and($chunks[1]->content)->toBe('lo')
        ->and($chunks[2]->content)->toBe('')
        ->and($chunks[2]->finishReason)->toBe('stop')
        ->and($chunks[2]->usage?->promptTokens)->toBe(6)
        ->and($chunks[2]->usage?->completionTokens)->toBe(1)
        ->and($chunks[2]->usage?->totalTokens)->toBe(7);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true
        && $request['store'] === false);
});

it('skips malformed stream events', function () {
    $body = implode("\n\n", [
        'data: '.json_encode(['event_type' => 'step.delta', 'delta' => ['type' => 'text', 'text' => 'first']]),
        'data: not-json',
        'data: '.json_encode(['event_type' => 'step.delta', 'delta' => ['type' => 'text', 'text' => 'second']]),
        'event: interaction.completed',
        'data: '.json_encode([
            'interaction' => ['id' => 'int_1', 'status' => 'completed'],
            'event_type' => 'interaction.completed',
        ]),
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $chunks = iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])));

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->content)->toBe('first')
        ->and($chunks[1]->content)->toBe('second')
        ->and($chunks[2]->content)->toBe('')
        ->and($chunks[2]->finishReason)->toBe('stop');
});

it('throws when the stream ends before the interaction completes', function () {
    $body = 'data: '.json_encode([
        'event_type' => 'step.delta',
        'delta' => ['type' => 'text', 'text' => 'Partial'],
    ])."\n\n";

    Http::fake([
        '*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);

    expect(fn () => iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]))))->toThrow(AiException::class, 'AI connection [gemini] streaming failed.');
});

it('maps failed interaction diagnostics from the errors field', function () {
    Http::fake([
        '*' => Http::response([
            'status' => 'failed',
            'errors' => [
                ['code' => 'internal', 'message' => 'Provider failure'],
            ],
        ]),
    ]);

    $exception = null;

    try {
        app(AiService::class)->generate(new AiRequest([
            ['role' => 'user', 'content' => 'Hello'],
        ]));
    } catch (AiException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toBe('AI connection [gemini] returned a provider error.')
        ->and($exception->providerCode)->toBe('internal')
        ->and($exception->providerMessage)->toBe('Provider failure');
});

it('throws a safe error when the interaction is blocked', function () {
    Http::fake([
        '*' => Http::response([
            'status' => 'failed',
            'error' => ['code' => 'safety_blocked', 'message' => 'The response was blocked by safety policy'],
        ]),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [gemini] blocked the response.');
});

it('throws when the stream contains a provider error', function () {
    Http::fake([
        '*' => Http::response(
            'event: error'."\n".'data: '.json_encode([
                'error' => ['code' => 'internal', 'message' => 'Provider failure'],
                'event_type' => 'error',
            ])."\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    expect(fn () => iterator_to_array(app(AiService::class)->stream(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ]))))->toThrow(AiException::class, 'AI connection [gemini] streaming failed.');
});

it('throws a safe error for rate limit failures', function () {
    Http::fake([
        '*' => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded']], 429),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [gemini] has exceeded its rate limit.');
});

it('throws when the response format is unexpected', function () {
    Http::fake([
        '*' => Http::response(['status' => 'completed', 'steps' => []]),
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [gemini] returned an unexpected response.');
});
