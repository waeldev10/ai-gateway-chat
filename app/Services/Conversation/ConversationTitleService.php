<?php

namespace App\Services\Conversation;

use App\AI\AiService;
use App\AI\DTOs\AiRequest;
use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Models\AiRequest as AiRequestRecord;
use App\Models\Conversation;
use Throwable;

class ConversationTitleService
{
    public function __construct(private readonly AiService $aiService) {}

    public function maybeGenerate(Conversation $conversation, string $userContent, string $assistantContent): void
    {
        if (! blank($conversation->title)) {
            return;
        }

        $title = $this->generateTitle($conversation, $userContent, $assistantContent);

        $conversation->update(['title' => $title]);
    }

    private function generateTitle(Conversation $conversation, string $userContent, string $assistantContent): string
    {
        [$provider, $model] = $this->configuredConnection();

        $aiRequest = AiRequestRecord::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'provider' => $provider,
            'model' => $model,
            'status' => AiRequestStatus::Started,
            'source' => AiSource::Web,
        ]);

        $startedAt = microtime(true);

        try {
            $response = $this->aiService->generate(
                AiRequest::fromMessages([
                    [
                        'role' => 'user',
                        'content' => <<<PROMPT
Suggest a short conversation title (maximum 6 words, no quotation marks, no ending punctuation) for this exchange:

User: $userContent
Assistant: $assistantContent
Title:
PROMPT,
                    ],
                ])
            );

            $title = trim((string) $response->content);

            $aiRequest->update([
                'provider' => $response->connection,
                'model' => $response->model,
                'status' => AiRequestStatus::Completed,
                'input_tokens' => $response->usage?->promptTokens,
                'output_tokens' => $response->usage?->completionTokens,
                'total_tokens' => $response->usage?->totalTokens,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return $title;
        } catch (Throwable $exception) {
            rescue(function () use ($aiRequest, $startedAt, $exception): void {
                $aiRequest->update([
                    'status' => AiRequestStatus::Failed,
                    'duration_ms' => $this->durationMs($startedAt),
                    'error_message' => $exception->getMessage(),
                ]);
            }, report: false);

            report($exception);

            return '';
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

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
