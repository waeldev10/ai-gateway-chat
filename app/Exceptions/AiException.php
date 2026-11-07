<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionNotConfigured(?string $connection): self
    {
        $connection = is_string($connection) && trim($connection) !== '' ? trim($connection) : 'default';

        return new self("AI connection [{$connection}] is not configured.");
    }

    public static function unsupportedDriver(string $connection, string $driver): self
    {
        return new self("AI connection [{$connection}] uses unsupported driver [{$driver}].");
    }

    public static function missingApiKey(string $connection): self
    {
        return new self("AI connection [{$connection}] is missing an API key.");
    }

    public static function missingModel(string $connection): self
    {
        return new self("AI connection [{$connection}] is missing a model.");
    }

    public static function invalidConfiguration(string $connection, string $field): self
    {
        return new self("AI connection [{$connection}] has an invalid [{$field}] configuration.");
    }

    public static function requestFailed(
        string $connection,
        int $status,
        ?string $providerMessage = null,
        ?string $providerCode = null,
        ?Throwable $previous = null,
    ): self {
        $message = match (true) {
            in_array($status, [401, 403], true) => "AI connection [{$connection}] rejected the configured credentials.",
            $status === 429 => "AI connection [{$connection}] has exceeded its rate limit.",
            default => "AI connection [{$connection}] request failed with HTTP status [{$status}].",
        };

        return new self($message, $providerCode, $providerMessage, $previous);
    }

    public static function connectionFailed(string $connection, ?Throwable $previous = null): self
    {
        return new self("AI connection [{$connection}] could not reach the provider.", previous: $previous);
    }

    public static function providerError(
        string $connection,
        ?string $providerMessage = null,
        ?string $providerCode = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            "AI connection [{$connection}] returned a provider error.",
            $providerCode,
            $providerMessage,
            $previous,
        );
    }

    public static function invalidResponse(string $connection, ?Throwable $previous = null): self
    {
        return new self("AI connection [{$connection}] returned an unexpected response.", previous: $previous);
    }

    public static function blockedResponse(string $connection, ?string $providerMessage = null): self
    {
        return new self(
            "AI connection [{$connection}] blocked the response.",
            providerMessage: $providerMessage,
        );
    }

    public static function streamFailed(
        string $connection,
        ?string $providerMessage = null,
        ?string $providerCode = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            "AI connection [{$connection}] streaming failed.",
            $providerCode,
            $providerMessage,
            $previous,
        );
    }
}
