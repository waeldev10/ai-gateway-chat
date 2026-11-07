<?php

namespace App\AI\Drivers;

use App\AI\Contracts\AiDriver;
use App\AI\Support\SseParser;
use App\Exceptions\AiException;
use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

abstract class HttpDriver implements AiDriver
{
    private readonly SseParser $sseParser;

    public function __construct(
        protected readonly string $connection,
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly string $model,
        protected readonly int $timeout,
    ) {
        $this->sseParser = new SseParser;
    }

    protected function send(string $url, array $payload, array $headers, bool $stream = false): Response
    {
        try {
            $request = Http::accept($stream ? 'text/event-stream' : 'application/json')
                ->connectTimeout($this->timeout)
                ->withHeaders($headers);

            if ($stream) {
                $response = $request
                    ->timeout(0)
                    ->withOptions(['stream' => true])
                    ->post($url, $payload);
            } else {
                $response = $request
                    ->timeout($this->timeout)
                    ->post($url, $payload);
            }
        } catch (ConnectionException $exception) {
            $this->logFailure('AI provider connection failed.', [
                'exception' => $exception::class,
            ]);

            throw AiException::connectionFailed($this->connection, $exception);
        }

        if ($response->failed()) {
            $detail = $this->providerErrorDetailFromResponse($response);

            $this->logFailure('AI provider request failed.', [
                'status' => $response->status(),
                ...$detail,
            ]);

            throw AiException::requestFailed(
                $this->connection,
                $response->status(),
                $detail['message'],
                $detail['code'],
            );
        }

        return $response;
    }

    protected function parseSse(StreamInterface $stream): Generator
    {
        yield from $this->sseParser->parse($stream);
    }

    protected function readStream(Response $response, Closure $parser): Generator
    {
        $body = $response->toPsrResponse()->getBody();

        try {
            yield from $parser($body);
        } catch (AiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('AI provider stream failed.', [
                'exception' => $exception::class,
            ]);

            throw AiException::streamFailed($this->connection, previous: $exception);
        } finally {
            $body->close();
        }
    }

    protected function unexpectedResponse(): AiException
    {
        $this->logFailure('AI provider returned an unexpected response.', [], 'warning');

        return AiException::invalidResponse($this->connection);
    }

    protected function providerError(array $payload): AiException
    {
        $detail = $this->providerErrorDetailFromArray($payload);

        $this->logFailure('AI provider returned an error.', $detail, 'warning');

        return AiException::providerError(
            $this->connection,
            $detail['message'],
            $detail['code'],
        );
    }

    protected function streamProviderError(array $payload): AiException
    {
        $detail = $this->providerErrorDetailFromArray($payload);

        $this->logFailure('AI provider stream error.', $detail, 'warning');

        return AiException::streamFailed(
            $this->connection,
            $detail['message'],
            $detail['code'],
        );
    }

    protected function blockedResponse(?string $reason = null): AiException
    {
        $this->logFailure('AI provider blocked the response.', [
            'message' => $reason,
        ], 'warning');

        return AiException::blockedResponse($this->connection, $reason);
    }

    protected function isSafetyFailure(array $detail): bool
    {
        $message = strtolower(($detail['code'] ?? '').' '.($detail['message'] ?? ''));

        return str_contains($message, 'safety')
            || str_contains($message, 'blocked')
            || str_contains($message, 'policy');
    }

    protected function normalizeFinishReason(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return match ($reason) {
            'stop', 'end_turn', 'stop_sequence', 'STOP', 'completed' => 'stop',
            'length', 'max_tokens', 'MAX_TOKENS', 'max_output_tokens', 'incomplete', 'requires_continuation' => 'length',
            'content_filter', 'refusal', 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' => 'content_filter',
            'tool_calls', 'function_call', 'tool_use', 'MALFORMED_FUNCTION_CALL', 'requires_action' => 'tool_use',
            default => 'other',
        };
    }

    protected function intValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function logFailure(string $message, array $context = [], string $level = 'error'): void
    {
        Log::log($level, $message, array_filter([
            'connection' => $this->connection,
            'driver' => class_basename(static::class),
            'provider_code' => $context['code'] ?? null,
            'provider_message' => $context['message'] ?? null,
            'status' => $context['status'] ?? null,
            'exception' => $context['exception'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function providerErrorDetailFromResponse(Response $response): array
    {
        $payload = json_decode($response->body(), true);

        if (! is_array($payload)) {
            return ['code' => null, 'message' => null];
        }

        return $this->providerErrorDetailFromArray($payload);
    }

    protected function providerErrorDetailFromArray(array $payload): array
    {
        $error = $payload['error'] ?? null;

        if (is_array($error)) {
            $code = $error['code'] ?? $error['type'] ?? null;
            $message = $error['message'] ?? null;
        } elseif (is_string($error)) {
            $code = $payload['code'] ?? $payload['type'] ?? null;
            $message = $error;
        } else {
            $code = $payload['code'] ?? $payload['type'] ?? null;
            $message = $payload['message'] ?? null;
        }

        return [
            'code' => is_string($code) || is_int($code) ? Str::limit(trim((string) $code), 100) : null,
            'message' => is_string($message) ? Str::limit(trim($message), 500) : null,
        ];
    }
}
