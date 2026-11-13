<?php

namespace App\Services\Message;

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiException;
use App\Models\AiPersona;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Persona\AiPromptService;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageGenerationService
{
    public function __construct(
        private readonly AiService $aiService,
        private readonly AiPromptService $promptService,
    ) {}

    public function attempt(
        Conversation $conversation,
        array $messages,
        AiSource $source = AiSource::Api,
        ?AiPersona $persona = null,
    ): array {
        [$provider, $model] = $this->configuredConnection();

        $aiRequest = AiRequestRecord::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'provider' => $provider,
            'model' => $model,
            'status' => AiRequestStatus::Started,
            'source' => $source,
        ]);

        $startedAt = microtime(true);

        try {
            $response = $this->aiService->generate(
                AiRequest::fromMessages(
                    $this->promptService->withSystemPrompt($conversation, $messages, $persona),
                ),
            );

            if (trim($response->content) === '') {
                throw AiException::invalidResponse($response->connection);
            }
        } catch (Throwable $exception) {
            $this->markAiRequestAsFailed($aiRequest, $startedAt, $exception);

            throw $exception;
        }

        return [
            'ai_request' => $aiRequest,
            'response' => $response,
            'started_at' => $startedAt,
        ];
    }

    public function persist(
        array $attempt,
        Conversation $conversation,
        ?Message $replace = null,
        ?Closure $beforePersist = null,
    ): Message {
        try {
            return DB::transaction(function () use ($attempt, $conversation, $replace, $beforePersist): Message {
                $lockedConversation = Conversation::query()
                    ->whereKey($conversation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($replace !== null) {
                    $replace = $lockedConversation->messages()
                        ->whereKey($replace->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($beforePersist !== null) {
                    $beforePersist();
                }

                $response = $attempt['response'];

                if ($replace !== null) {
                    $replace->update([
                        'content' => $response->content,
                        'status' => MessageStatus::Complete,
                    ]);

                    $message = $replace;
                } else {
                    $message = $lockedConversation->messages()->create([
                        'role' => MessageRole::Assistant,
                        'content' => $response->content,
                        'status' => MessageStatus::Complete,
                    ]);
                }

                $attempt['ai_request']->update([
                    'message_id' => $message->id,
                    'provider' => $response->connection,
                    'model' => $response->model,
                    'status' => AiRequestStatus::Completed,
                    'input_tokens' => $response->usage?->promptTokens,
                    'output_tokens' => $response->usage?->completionTokens,
                    'total_tokens' => $response->usage?->totalTokens,
                    'duration_ms' => $this->durationMs($attempt['started_at']),
                ]);

                return $message;
            });
        } catch (Throwable $exception) {
            $this->markAiRequestAsFailed($attempt['ai_request'], $attempt['started_at'], $exception);

            throw $exception;
        }
    }

    public function generate(
        Conversation $conversation,
        array $messages,
        AiSource $source = AiSource::Api,
        ?Message $replace = null,
        ?AiPersona $persona = null,
    ): Message {
        return $this->persist(
            $this->attempt($conversation, $messages, $source, $persona),
            $conversation,
            $replace,
        );
    }

    private function configuredConnection(): array
    {
        $connection = config('ai.default');
        $provider = is_string($connection) && trim($connection) !== '' ? trim($connection) : 'unknown';
        $configuration = config("ai.connections.{$provider}");
        $configuredModel = is_array($configuration) ? ($configuration['model'] ?? null) : null;
        $model = is_string($configuredModel) && trim($configuredModel) !== ''
            ? trim($configuredModel)
            : 'unknown';

        return [$provider, $model];
    }

    private function markAiRequestAsFailed(AiRequestRecord $aiRequest, float $startedAt, Throwable $exception): void
    {
        rescue(function () use ($aiRequest, $startedAt, $exception): void {
            $aiRequest->update([
                'status' => AiRequestStatus::Failed,
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $exception->getMessage(),
            ]);
        }, report: false);
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
