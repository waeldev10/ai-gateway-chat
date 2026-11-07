<?php

use App\AI\AiDriverFactory;
use App\AI\AiService;
use App\AI\Drivers\AnthropicMessagesDriver;
use App\AI\Drivers\ChatCompletionsDriver;
use App\AI\Drivers\GeminiInteractionsDriver;
use App\AI\Drivers\ResponsesDriver;
use App\AI\DTOs\AiRequest;
use App\Exceptions\AiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('defines the supported ai connections', function () {
    $connections = config('ai.connections');

    expect($connections)->toBeArray()
        ->and($connections)->toHaveKeys([
            'openai',
            'openai-responses',
            'anthropic',
            'gemini',
            'deepseek',
            'opencode',
            'glm',
            'kimi',
            'openrouter',
            'ollama',
            'qwen',
            'llama',
            'grok',
        ]);

    foreach ($connections as $connection) {
        expect($connection)->toHaveKeys([
            'driver',
            'api_key',
            'base_url',
            'model',
            'timeout',
        ]);
    }

    expect($connections['openai']['driver'])->toBe('chat-completions')
        ->and($connections['openai-responses']['driver'])->toBe('responses')
        ->and($connections['anthropic']['driver'])->toBe('anthropic-messages')
        ->and($connections['gemini']['driver'])->toBe('gemini-interactions')
        ->and($connections['deepseek']['driver'])->toBe('chat-completions')
        ->and($connections['opencode']['driver'])->toBe('chat-completions')
        ->and($connections['glm']['driver'])->toBe('chat-completions')
        ->and($connections['kimi']['driver'])->toBe('chat-completions')
        ->and($connections['openrouter']['driver'])->toBe('chat-completions')
        ->and($connections['ollama']['driver'])->toBe('chat-completions')
        ->and($connections['qwen']['driver'])->toBe('chat-completions')
        ->and($connections['llama']['driver'])->toBe('chat-completions')
        ->and($connections['grok']['driver'])->toBe('chat-completions');
});

it('resolves the driver for each protocol', function () {
    config()->set('ai.connections.chat', [
        'driver' => 'chat-completions',
        'api_key' => 'chat-key',
        'base_url' => 'https://chat.example.test/v1',
        'model' => 'chat-model',
        'timeout' => 30,
    ]);

    config()->set('ai.connections.responses', [
        'driver' => 'responses',
        'api_key' => 'responses-key',
        'base_url' => 'https://responses.example.test/v1',
        'model' => 'responses-model',
        'timeout' => 30,
    ]);

    config()->set('ai.connections.messages', [
        'driver' => 'anthropic-messages',
        'api_key' => 'messages-key',
        'base_url' => 'https://messages.example.test/v1',
        'model' => 'messages-model',
        'timeout' => 30,
        'max_tokens' => 512,
    ]);

    config()->set('ai.connections.google', [
        'driver' => 'gemini-interactions',
        'api_key' => 'google-key',
        'base_url' => 'https://google.example.test/v1beta',
        'model' => 'google-model',
        'timeout' => 30,
    ]);

    $factory = app(AiDriverFactory::class);

    expect($factory->make('chat'))->toBeInstanceOf(ChatCompletionsDriver::class)
        ->and($factory->make('responses'))->toBeInstanceOf(ResponsesDriver::class)
        ->and($factory->make('messages'))->toBeInstanceOf(AnthropicMessagesDriver::class)
        ->and($factory->make('google'))->toBeInstanceOf(GeminiInteractionsDriver::class);
});

it('uses the configured default connection', function () {
    config()->set('ai.default', 'deepseek');
    config()->set('ai.connections.deepseek', [
        'driver' => 'chat-completions',
        'api_key' => 'deepseek-key',
        'base_url' => 'https://api.deepseek.com/v1',
        'model' => 'deepseek-model',
        'timeout' => 30,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'model' => 'deepseek-model-2026',
            'choices' => [
                ['message' => ['content' => 'Hello'], 'finish_reason' => 'stop'],
            ],
        ]),
    ]);

    $response = app(AiService::class)->generate(AiRequest::fromMessages([
        ['role' => 'user', 'content' => 'Hello'],
    ]));

    expect($response->content)->toBe('Hello')
        ->and($response->connection)->toBe('deepseek')
        ->and($response->model)->toBe('deepseek-model-2026');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.deepseek.com/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer deepseek-key'));
});

it('throws when the default connection is not configured', function () {
    config()->set('ai.default', 'missing');

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [missing] is not configured.');
});

it('throws when the default connection is missing', function () {
    config()->set('ai.default', '');

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [default] is not configured.');
});

it('throws when the api key is missing', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => ' ',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] is missing an API key.');
});

it('throws when the model is missing', function () {
    config()->set('ai.default', 'openai');
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.com/v1',
        'model' => null,
        'timeout' => 30,
    ]);

    expect(fn () => app(AiService::class)->generate(new AiRequest([
        ['role' => 'user', 'content' => 'Hello'],
    ])))->toThrow(AiException::class, 'AI connection [openai] is missing a model.');
});

it('throws when the driver is not supported', function () {
    config()->set('ai.connections.custom', [
        'driver' => 'unsupported',
        'api_key' => 'test-key',
        'base_url' => 'https://example.test/v1',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    expect(fn () => app(AiDriverFactory::class)->make('custom'))
        ->toThrow(AiException::class, 'AI connection [custom] uses unsupported driver [unsupported].');
});

it('rejects the old driver identifiers', function () {
    config()->set('ai.connections.legacy-chat', [
        'driver' => 'openai-chat',
        'api_key' => 'test-key',
        'base_url' => 'https://example.test/v1',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    config()->set('ai.connections.legacy-responses', [
        'driver' => 'openai-responses',
        'api_key' => 'test-key',
        'base_url' => 'https://example.test/v1',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    expect(fn () => app(AiDriverFactory::class)->make('legacy-chat'))
        ->toThrow(AiException::class, 'AI connection [legacy-chat] uses unsupported driver [openai-chat].')
        ->and(fn () => app(AiDriverFactory::class)->make('legacy-responses'))
        ->toThrow(AiException::class, 'AI connection [legacy-responses] uses unsupported driver [openai-responses].');
});

it('throws when the connection configuration is invalid', function () {
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'test-model',
        'timeout' => 0,
    ]);

    expect(fn () => app(AiDriverFactory::class)->make('openai'))
        ->toThrow(AiException::class, 'AI connection [openai] has an invalid [timeout] configuration.');
});

it('throws when the base url is invalid', function () {
    config()->set('ai.connections.openai', [
        'driver' => 'chat-completions',
        'api_key' => 'test-key',
        'base_url' => 'not-a-url',
        'model' => 'test-model',
        'timeout' => 30,
    ]);

    expect(fn () => app(AiDriverFactory::class)->make('openai'))
        ->toThrow(AiException::class, 'AI connection [openai] has an invalid [base_url] configuration.');
});

it('throws when the anthropic max tokens configuration is invalid', function () {
    config()->set('ai.connections.anthropic', [
        'driver' => 'anthropic-messages',
        'api_key' => 'test-key',
        'base_url' => 'https://api.anthropic.com/v1',
        'model' => 'test-model',
        'timeout' => 30,
        'max_tokens' => 0,
    ]);

    expect(fn () => app(AiDriverFactory::class)->make('anthropic'))
        ->toThrow(AiException::class, 'AI connection [anthropic] has an invalid [max_tokens] configuration.');
});
