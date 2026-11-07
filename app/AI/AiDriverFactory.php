<?php

namespace App\AI;

use App\AI\Contracts\AiDriver;
use App\AI\Drivers\AnthropicMessagesDriver;
use App\AI\Drivers\ChatCompletionsDriver;
use App\AI\Drivers\GeminiInteractionsDriver;
use App\AI\Drivers\ResponsesDriver;
use App\Exceptions\AiException;

class AiDriverFactory
{
    public function make(string $connection): AiDriver
    {
        $connection = trim($connection);

        if ($connection === '') {
            throw AiException::connectionNotConfigured($connection);
        }

        $config = config("ai.connections.{$connection}");

        if (! is_array($config)) {
            throw AiException::connectionNotConfigured($connection);
        }

        $driver = $this->stringValue($config['driver'] ?? null);
        $apiKey = $this->stringValue($config['api_key'] ?? null);
        $baseUrl = $this->stringValue($config['base_url'] ?? null);
        $model = $this->stringValue($config['model'] ?? null);
        $timeout = $config['timeout'] ?? null;

        if ($driver === null) {
            throw AiException::invalidConfiguration($connection, 'driver');
        }

        if ($apiKey === null) {
            throw AiException::missingApiKey($connection);
        }

        if ($model === null) {
            throw AiException::missingModel($connection);
        }

        if ($baseUrl === null || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw AiException::invalidConfiguration($connection, 'base_url');
        }

        if (! is_numeric($timeout) || (int) $timeout < 1) {
            throw AiException::invalidConfiguration($connection, 'timeout');
        }

        $baseUrl = rtrim($baseUrl, '/');
        $timeout = (int) $timeout;

        return match ($driver) {
            'chat-completions' => new ChatCompletionsDriver($connection, $apiKey, $baseUrl, $model, $timeout),
            'responses' => new ResponsesDriver($connection, $apiKey, $baseUrl, $model, $timeout),
            'anthropic-messages' => new AnthropicMessagesDriver(
                $connection,
                $apiKey,
                $baseUrl,
                $model,
                $timeout,
                $this->positiveInteger($connection, $config['max_tokens'] ?? 4096, 'max_tokens'),
                $this->stringValue($config['version'] ?? null) ?? '2023-06-01',
            ),
            'gemini-interactions' => new GeminiInteractionsDriver($connection, $apiKey, $baseUrl, $model, $timeout),
            default => throw AiException::unsupportedDriver($connection, $driver),
        };
    }

    private function positiveInteger(string $connection, mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw AiException::invalidConfiguration($connection, $field);
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
