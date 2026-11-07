<?php

namespace App\AI\Drivers;

use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use Generator;
use Psr\Http\Message\StreamInterface;

final class ChatCompletionsDriver extends HttpDriver
{
    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->send(
            $this->baseUrl.'/chat/completions',
            $this->payload($request),
            $this->headers(),
        );

        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->unexpectedResponse();
        }

        if (array_key_exists('error', $payload)) {
            throw $this->providerError($payload);
        }

        $content = data_get($payload, 'choices.0.message.content');

        if (! is_string($content)) {
            throw $this->unexpectedResponse();
        }

        $responseModel = data_get($payload, 'model');
        $finishReason = data_get($payload, 'choices.0.finish_reason');

        return new AiResponse(
            content: $content,
            connection: $this->connection,
            model: is_string($responseModel) && $responseModel !== '' ? $responseModel : $this->model,
            finishReason: $this->normalizeFinishReason(is_string($finishReason) ? $finishReason : null),
            usage: $this->usage(data_get($payload, 'usage')),
        );
    }

    public function stream(AiRequest $request): Generator
    {
        $response = $this->send(
            $this->baseUrl.'/chat/completions',
            $this->payload($request, stream: true),
            $this->headers(),
            stream: true,
        );

        yield from $this->readStream(
            $response,
            fn (StreamInterface $body): Generator => $this->readStreamEvents($body),
        );
    }

    private function readStreamEvents(StreamInterface $body): Generator
    {
        $finishReason = null;
        $usage = null;

        foreach ($this->parseSse($body) as $event) {
            if ($event['data'] === '[DONE]') {
                break;
            }

            $payload = json_decode($event['data'], true);

            if (! is_array($payload)) {
                continue;
            }

            if (array_key_exists('error', $payload) || $event['event'] === 'error') {
                throw $this->streamProviderError($payload);
            }

            $content = data_get($payload, 'choices.0.delta.content');

            if (is_string($content) && $content !== '') {
                yield new AiChunk($content);
            }

            $eventFinishReason = data_get($payload, 'choices.0.finish_reason');

            if (is_string($eventFinishReason) && $eventFinishReason !== '') {
                $finishReason = $eventFinishReason;
            }

            $eventUsage = $this->usage(data_get($payload, 'usage'));

            if ($eventUsage !== null) {
                $usage = $eventUsage;
            }
        }

        $finishReason = $this->normalizeFinishReason($finishReason);

        if ($finishReason !== null || $usage !== null) {
            yield new AiChunk('', $finishReason, $usage);
        }
    }

    private function payload(AiRequest $request, bool $stream = false): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $request->messages,
        ];

        if ($stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    private function usage(mixed $usage): ?AiUsage
    {
        if (! is_array($usage)) {
            return null;
        }

        return AiUsage::fromTokens(
            promptTokens: $this->intValue($usage['prompt_tokens'] ?? null),
            completionTokens: $this->intValue($usage['completion_tokens'] ?? null),
            totalTokens: $this->intValue($usage['total_tokens'] ?? null),
        );
    }
}
