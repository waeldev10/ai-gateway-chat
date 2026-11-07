<?php

namespace App\AI\Drivers;

use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use Generator;
use Psr\Http\Message\StreamInterface;

final class AnthropicMessagesDriver extends HttpDriver
{
    public function __construct(
        string $connection,
        string $apiKey,
        string $baseUrl,
        string $model,
        int $timeout,
        private readonly int $maxTokens,
        private readonly string $version,
    ) {
        parent::__construct($connection, $apiKey, $baseUrl, $model, $timeout);
    }

    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->send($this->baseUrl.'/messages', $this->payload($request), $this->headers());

        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->unexpectedResponse();
        }

        if (array_key_exists('error', $payload) || ($payload['type'] ?? null) === 'error') {
            throw $this->providerError($payload);
        }

        $content = $this->extractText($payload['content'] ?? null);

        if ($content === null) {
            throw $this->unexpectedResponse();
        }

        $responseModel = $payload['model'] ?? null;
        $stopReason = $payload['stop_reason'] ?? null;

        return new AiResponse(
            content: $content,
            connection: $this->connection,
            model: is_string($responseModel) && $responseModel !== '' ? $responseModel : $this->model,
            finishReason: $this->normalizeFinishReason(is_string($stopReason) ? $stopReason : null),
            usage: $this->usage($payload['usage'] ?? null),
        );
    }

    public function stream(AiRequest $request): Generator
    {
        $payload = $this->payload($request);
        $payload['stream'] = true;

        $response = $this->send($this->baseUrl.'/messages', $payload, $this->headers(), stream: true);

        yield from $this->readStream(
            $response,
            fn (StreamInterface $body): Generator => $this->readStreamEvents($body),
        );
    }

    private function readStreamEvents(StreamInterface $body): Generator
    {
        $finishReason = null;
        $promptTokens = null;
        $completionTokens = null;

        foreach ($this->parseSse($body) as $event) {
            if ($event['data'] === '[DONE]') {
                break;
            }

            $payload = json_decode($event['data'], true);

            if (! is_array($payload)) {
                continue;
            }

            $type = $payload['type'] ?? $event['event'];

            if ($type === 'error') {
                throw $this->streamProviderError($payload);
            }

            if ($type === 'message_start') {
                $message = $payload['message'] ?? null;

                if (is_array($message)) {
                    $promptTokens = $this->intValue(data_get($message, 'usage.input_tokens')) ?? $promptTokens;
                    $completionTokens = $this->intValue(data_get($message, 'usage.output_tokens')) ?? $completionTokens;
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $delta = $payload['delta'] ?? null;

                if (is_array($delta) && ($delta['type'] ?? null) === 'text_delta') {
                    $text = $delta['text'] ?? null;

                    if (is_string($text) && $text !== '') {
                        yield new AiChunk($text);
                    }
                }

                continue;
            }

            if ($type === 'message_delta') {
                $stopReason = data_get($payload, 'delta.stop_reason');

                if (is_string($stopReason) && $stopReason !== '') {
                    $finishReason = $stopReason;
                }

                $completionTokens = $this->intValue(data_get($payload, 'usage.output_tokens')) ?? $completionTokens;
                $promptTokens = $this->intValue(data_get($payload, 'usage.input_tokens')) ?? $promptTokens;

                continue;
            }

            if ($type === 'message_stop') {
                break;
            }
        }

        $finishReason = $this->normalizeFinishReason($finishReason);
        $usage = AiUsage::fromTokens($promptTokens, $completionTokens);

        if ($finishReason !== null || $usage !== null) {
            yield new AiChunk('', $finishReason, $usage);
        }
    }

    private function payload(AiRequest $request): array
    {
        $system = [];
        $messages = [];

        foreach ($request->messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system[] = $content;

                continue;
            }

            $messages[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
        ];

        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        return $payload;
    }

    private function extractText(mixed $content): ?string
    {
        if (! is_array($content)) {
            return null;
        }

        $text = '';
        $hasText = false;

        foreach ($content as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            $value = $block['text'] ?? null;

            if (is_string($value)) {
                $text .= $value;
                $hasText = true;
            }
        }

        return $hasText ? $text : null;
    }

    private function usage(mixed $usage): ?AiUsage
    {
        if (! is_array($usage)) {
            return null;
        }

        return AiUsage::fromTokens(
            promptTokens: $this->intValue($usage['input_tokens'] ?? null),
            completionTokens: $this->intValue($usage['output_tokens'] ?? null),
        );
    }

    private function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
        ];
    }
}
