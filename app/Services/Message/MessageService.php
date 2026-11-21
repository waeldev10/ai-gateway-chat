<?php

namespace App\Services\Message;

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\ConversationTitleService;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /**
     * Persist the user message and stream the assistant reply while holding the
     * conversation lock.
     *
     * @return Generator<int, string, mixed, array{
     *     user_message: Message,
     *     assistant_message: ?Message,
     *     failed: bool
     * }>
     */
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
                    $this->titles->maybeGenerate($conversation, $content, $result['assistant_message']->content);
                }

                return [
                    'user_message' => $userMessage,
                    'assistant_message' => $result['assistant_message'],
                    'failed' => $result['failed'],
                ];
            },
        );
    }

    public function continueConversation(
        Conversation $conversation,
        AiSource $source = AiSource::Api,
        ?AiPersona $persona = null,
    ): Message {
        return $this->operationLock->run($conversation, function () use ($conversation, $source, $persona): Message {
            $history = DB::transaction(function () use ($conversation): array {
                $lockedConversation = $this->operationLock->lockForUpdate($conversation);

                if ($this->contextService->latestMessage($lockedConversation)?->status === MessageStatus::Pending) {
                    throw ValidationException::withMessages([
                        'conversation' => 'لا يمكن متابعة المحادثة لأن آخر رسالة قيد المعالجة.',
                    ]);
                }

                $history = $this->contextService->completedHistory($lockedConversation);

                if ($history === []) {
                    throw ValidationException::withMessages([
                        'conversation' => 'لا توجد رسائل مكتملة لمتابعة المحادثة.',
                    ]);
                }

                return $history;
            });

            return $this->generationService->generate($conversation, $history, $source, null, $persona);
        });
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

    /**
     * @return array{0: Message, 1: array<int, array{role: string, content: string}>}
     */
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
