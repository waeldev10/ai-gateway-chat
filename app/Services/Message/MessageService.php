<?php

namespace App\Services\Message;

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Services\Conversation\ConversationTitleService;
use Generator;
use Illuminate\Support\Facades\DB;

/** ينسق إرسال المستخدم، ويفوض حفظ الرسائل وبناء السياق والتوليد لخدماتها المختصة */
class MessageService
{
    public function __construct(
        private readonly MessageContextService $contextService,
        private readonly MessageGenerationService $generationService,
        private readonly ConversationOperationLock $operationLock,
        private readonly ConversationTitleService $titles,
    ) {}

    public function send(
        Conversation $conversation,
        string $content,
        AiSource $source = AiSource::Api,
        ?AiPersona $persona = null,
    ): array {
        return $this->operationLock->run(
            $conversation,
            fn (): array => $this->sendLocked($conversation, $content, $source, $persona),
        );
    }

    public function stream(
        Conversation $conversation,
        string $content,
        AiSource $source = AiSource::Web,
        ?AiPersona $persona = null,
    ): Generator {
        return $this->operationLock->runGenerator(
            $conversation,
            function () use ($conversation, $content, $source, $persona): Generator {
                [$userMessage, $history] = $this->persistUserMessage($conversation, $content);

                $result = yield from $this->generationService->stream(
                    $conversation,
                    $history,
                    $source,
                    $persona,
                );

                if (blank($conversation->title) && ! $result['failed'] && $result['assistant_message'] !== null) {
                    $this->titles->maybeGenerate($conversation, $content, $result['assistant_message']->content, $source);
                }

                return [
                    'user_message' => $userMessage,
                    'assistant_message' => $result['assistant_message'],
                    'failed' => $result['failed'],
                    'connection_failed' => $result['connection_failed'],
                ];
            },
        );
    }

    private function sendLocked(
        Conversation $conversation,
        string $content,
        AiSource $source,
        ?AiPersona $persona,
    ): array {
        [$userMessage, $history] = $this->persistUserMessage($conversation, $content);

        $assistantMessage = $this->generationService->generate(
            $conversation,
            $history,
            $source,
            null,
            $persona,
        );

        return [
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ];
    }

    private function persistUserMessage(Conversation $conversation, string $content): array
    {
        return DB::transaction(function () use ($conversation, $content): array {
            $lockedConversation = $this->operationLock->lockForUpdate($conversation);

            $history = $this->contextService->completedHistory(
                $lockedConversation,
                null,
                [
                    'role' => MessageRole::User->value,
                    'content' => $content,
                ],
            );

            $userMessage = $lockedConversation->messages()->create([
                'role' => MessageRole::User,
                'content' => $content,
                'status' => MessageStatus::Complete,
            ]);

            return [$userMessage, $history];
        });
    }
}
