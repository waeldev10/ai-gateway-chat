<?php

namespace App\Services\Message;

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
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
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** يضيف تعليمات الشخصية للسياق، ويرسل الطلب إلى AiService، ثم يسجل حالته ويحفظ الرد أو أجزاء البث. */
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
        ?Closure $beforePersist = null,
    ): Message {
        return $this->persist(
            $this->attempt($conversation, $messages, $source, $persona),
            $conversation,
            $replace,
            $beforePersist,
        );
    }

    /**
     * Stream a generated assistant reply while recording the AI request.
     *
     * Failures are reported through the returned array instead of being thrown
     * once streaming has started, because response headers may already be sent.
     *
     * @return Generator<int, string, mixed, array{assistant_message: ?Message, failed: bool, connection_failed: bool}>
     */
    public function stream(
        Conversation $conversation,
        array $messages,
        AiSource $source = AiSource::Web,
        ?AiPersona $persona = null,
        ?Message $replace = null,
        ?Closure $beforePersist = null,
    ): Generator {
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
        $content = '';
        $finishReason = null;
        $usage = null;

        try {
            $request = AiRequest::fromMessages(
                $this->promptService->withSystemPrompt($conversation, $messages, $persona),
            );

            foreach ($this->aiService->stream($request) as $chunk) {
                if ($this->isStopped($conversation)) {
                    $this->clearStop($conversation);
                    break;
                }

                if ($chunk->usage !== null) {
                    $usage = $chunk->usage;
                }

                if ($chunk->finishReason !== null) {
                    $finishReason = $chunk->finishReason;
                }

                if ($chunk->content === '') {
                    continue;
                }

                $content .= $chunk->content;

                yield $chunk->content;
            }

            if (trim($content) === '') {
                throw AiException::invalidResponse($provider);
            }

            $message = $this->persist(
                [
                    'ai_request' => $aiRequest,
                    'response' => new AiResponse($content, $provider, $model, $finishReason, $usage),
                    'started_at' => $startedAt,
                ],
                $conversation,
                $replace,
                $beforePersist,
            );

            return [
                'assistant_message' => $message,
                'failed' => false,
                'connection_failed' => false,
            ];
        } catch (Throwable $exception) {
            $message = $content === ''
                ? null
                : $this->persistFailedMessage($aiRequest, $conversation, $content, $startedAt, $exception, $replace, $beforePersist);

            if ($message === null) {
                $this->markAiRequestAsFailed($aiRequest, $startedAt, $exception);
            }

            return [
                'assistant_message' => $message,
                'failed' => true,
                'connection_failed' => $exception instanceof AiException && $exception->connectionFailure,
            ];
        }
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

    private function persistFailedMessage(
        AiRequestRecord $aiRequest,
        Conversation $conversation,
        string $content,
        float $startedAt,
        Throwable $exception,
        ?Message $replace = null,
        ?Closure $beforePersist = null,
    ): ?Message {
        try {
            $message = DB::transaction(function () use ($conversation, $content, $replace, $beforePersist): Message {
                $lockedConversation = Conversation::query()
                    ->whereKey($conversation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($replace !== null) {
                    $message = $lockedConversation->messages()
                        ->whereKey($replace->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($beforePersist !== null) {
                    $beforePersist();
                }

                if ($replace !== null) {
                    $message->update([
                        'content' => $content,
                        'status' => MessageStatus::Failed,
                    ]);

                    return $message;
                }

                return $lockedConversation->messages()->create([
                    'role' => MessageRole::Assistant,
                    'content' => $content,
                    'status' => MessageStatus::Failed,
                ]);
            });
        } catch (Throwable $persistenceException) {
            report($persistenceException);

            return null;
        }

        rescue(function () use ($aiRequest, $message, $startedAt, $exception): void {
            $aiRequest->update([
                'message_id' => $message->id,
                'status' => AiRequestStatus::Failed,
                'duration_ms' => $this->durationMs($startedAt),
                'error_message' => $exception->getMessage(),
            ]);
        }, report: false);

        return $message;
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

    private function isStopped(Conversation $conversation): bool
    {
        return (bool) Cache::get('message-stop:'.$conversation->getKey());
    }

    private function clearStop(Conversation $conversation): void
    {
        Cache::forget('message-stop:'.$conversation->getKey());
    }

    public function stop(Conversation $conversation): void
    {
        Cache::put('message-stop:'.$conversation->getKey(), true, now()->addMinutes(5));
    }
}
