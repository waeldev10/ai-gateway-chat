<?php

namespace App\AI\Drivers;

use App\AI\DTOs\AiChunk;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use App\AI\DTOs\AiUsage;
use App\Exceptions\AiException;
use Generator;
use Psr\Http\Message\StreamInterface;

final class GeminiInteractionsDriver extends HttpDriver
{
    public function generate(AiRequest $request): AiResponse
    {
        $response = $this->send(
            $this->baseUrl.'/interactions',
            $this->payload($request),
            $this->headers(),
        );

        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->unexpectedResponse();
        }

        if (($payload['status'] ?? null) === 'failed') {
            $this->throwFailedPayload($payload);
        }

        if (array_key_exists('error', $payload)) {
            throw $this->providerError($payload);
        }

        $content = $this->extractText($payload['steps'] ?? null);

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
            $this->baseUrl.'/interactions',
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
        $completed = false;

        foreach ($this->parseSse($body) as $event) {
            if ($event['data'] === '[DONE]') {
                break;
            }

            $payload = json_decode($event['data'], true);

            if (! is_array($payload)) {
                continue;
            }

            $type = $payload['event_type'] ?? $event['event'];

            if ($type === 'error') {
                throw $this->streamProviderError($payload);
            }

            if ($type === 'step.delta') {
                $delta = $payload['delta'] ?? null;

                if (! is_array($delta)) {
                    continue;
                }

                $text = $delta['text'] ?? null;

                if (($delta['type'] ?? null) === 'text' && is_string($text) && $text !== '') {
                    yield new AiChunk($text);
                }

                continue;
            }

            if ($type === 'interaction.status_update' && ($payload['status'] ?? null) === 'failed') {
                $this->throwFailedPayload($payload);
            }

            if ($type !== 'interaction.completed') {
                continue;
            }

            $interaction = $payload['interaction'] ?? null;

            if (! is_array($interaction)) {
                continue;
            }

            if (($interaction['status'] ?? null) === 'failed') {
                $this->throwFailedPayload($interaction);
            }

            $completed = true;
            $finishReason = $this->finishReason($interaction);
            $usage = $this->usage($interaction['usage'] ?? null);
        }

        if (! $completed) {
            throw AiException::streamFailed(
                $this->connection,
                'The provider stream ended before the interaction completed.',
            );
        }

        if ($finishReason !== null || $usage !== null) {
            yield new AiChunk('', $finishReason, $usage);
        }
    }

    private function payload(AiRequest $request, bool $stream = false): array
    {
        $system = [];
        $input = [];

        foreach ($request->messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system') {
                $system[] = $content;

                continue;
            }

            $input[] = [
                'type' => $role === 'assistant' ? 'model_output' : 'user_input',
                'content' => [
                    ['type' => 'text', 'text' => $content],
                ],
            ];
        }

        $payload = [
            'model' => $this->model,
            'input' => $input,
            'store' => false,
        ];

        if ($system !== []) {
            $payload['system_instruction'] = implode("\n\n", $system);
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    private function extractText(mixed $steps): ?string
    {
        if (! is_array($steps)) {
            return null;
        }

        $text = '';
        $hasText = false;

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            $content = $step['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'text') {
                    continue;
                }

                $value = $item['text'] ?? null;

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
            'incomplete', 'requires_continuation' => 'length',
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
            promptTokens: $this->intValue($usage['total_input_tokens'] ?? null),
            completionTokens: $this->intValue($usage['total_output_tokens'] ?? null),
            totalTokens: $this->intValue($usage['total_tokens'] ?? null),
        );
    }

    private function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    private function throwFailedPayload(array $payload): never
    {
        $payload = $this->withProviderError($payload);

        $detail = $this->providerErrorDetailFromArray($payload);

        if ($this->isSafetyFailure($detail)) {
            throw $this->blockedResponse($detail['message']);
        }

        throw $this->providerError($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withProviderError(array $payload): array
    {
        if (array_key_exists('error', $payload)) {
            return $payload;
        }

        $errors = $payload['errors'] ?? null;
        $error = is_array($errors) ? ($errors[0] ?? null) : null;

        if (is_array($error)) {
            $payload['error'] = $error;
        }

        return $payload;
    }
}
