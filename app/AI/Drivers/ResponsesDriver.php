<?php

namespace App\AI\Drivers;

use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use Generator;
use Psr\Http\Message\StreamInterface;

final class ResponsesDriver extends HttpDriver
{
    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->send(
            $this->baseUrl.'/responses',
            $this->payload($request),
            $this->headers(),
        );

        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->unexpectedResponse();
        }

        if (array_key_exists('error', $payload) || ($payload['status'] ?? null) === 'failed') {
            throw $this->providerError($payload);
        }

        $content = $this->extractText($payload);

        if ($content === null) {
            throw $this->unexpectedResponse();
        }

        $responseModel = $payload['model'] ?? null;

        return new AiResponse(
            content: $content,
            connection: $this->connection,
            model: is_string($responseModel) && $responseModel !== '' ? $responseModel : $this->model,
            finishReason: $this->finishReason($payload),
            usage: $this->usage($payload['usage'] ?? null),
        );
    }

    public function stream(AiRequest $request): Generator
    {
        $response = $this->send(
            $this->baseUrl.'/responses',
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

            $type = $payload['type'] ?? $event['event'];

            if ($type === 'error') {
                throw $this->streamProviderError($payload);
            }

            if ($type === 'response.failed') {
                throw $this->providerError($this->responsePayload($payload));
            }

            if ($type === 'response.output_text.delta') {
                $delta = $payload['delta'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    yield new AiChunk($delta);
                }

                continue;
            }

            if ($type === 'response.completed' || $type === 'response.incomplete') {
                $responsePayload = $this->responsePayload($payload);

                if ($responsePayload === []) {
                    continue;
                }

                if (($responsePayload['status'] ?? null) === 'failed') {
                    throw $this->providerError($responsePayload);
                }

                $finishReason = $this->finishReason($responsePayload);
                $usage = $this->usage($responsePayload['usage'] ?? null);
            }
        }

        if ($finishReason !== null || $usage !== null) {
            yield new AiChunk('', $finishReason, $usage);
        }
    }

    private function responsePayload(array $payload): array
    {
        $response = $payload['response'] ?? null;

        return is_array($response) ? $response : $payload;
    }

    private function payload(AiRequest $request, bool $stream = false): array
    {
        $payload = [
            'model' => $this->model,
            'input' => $request->messages,
            'store' => false,
        ];

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    private function extractText(array $payload): ?string
    {
        $output = $payload['output'] ?? null;

        if (! is_array($output)) {
            return null;
        }

        $text = '';
        $hasText = false;

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $contentParts = $item['content'] ?? null;

            if (! is_array($contentParts)) {
                continue;
            }

            foreach ($contentParts as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $value = $part['text'] ?? null;

                if (is_string($value)) {
                    $text .= $value;
                    $hasText = true;
                }
            }
        }

        return $hasText ? $text : null;
    }

    private function finishReason(array $payload): ?string
    {
        return match ($payload['status'] ?? null) {
            'completed' => 'stop',
            'incomplete' => match (data_get($payload, 'incomplete_details.reason')) {
                'max_output_tokens' => 'length',
                'content_filter' => 'content_filter',
                default => 'other',
            },
            'requires_action' => 'tool_use',
            'cancelled' => 'other',
            default => null,
        };
    }

    private function usage(mixed $usage): ?AiUsage
    {
        if (! is_array($usage)) {
            return null;
        }

        return AiUsage::fromTokens(
            promptTokens: $this->intValue($usage['input_tokens'] ?? null),
            completionTokens: $this->intValue($usage['output_tokens'] ?? null),
            totalTokens: $this->intValue($usage['total_tokens'] ?? null),
        );
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }
}
