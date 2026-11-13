<?php

namespace App\Services\Message;

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageService
{
    public function __construct(
        private readonly MessageContextService $contextService,
        private readonly MessageGenerationService $generationService,
        private readonly ConversationOperationLock $operationLock,
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
        [$userMessage, $history] = DB::transaction(function () use ($conversation, $content): array {
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

            if (blank($lockedConversation->title)) {
                $lockedConversation->update(['title' => $this->titleFrom($content)]);
            }

            return [$userMessage, $history];
        });

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

    private function titleFrom(string $content): string
    {
        $title = Str::limit(Str::squish($content), 60, '…');

        return $title !== '' ? $title : 'محادثة جديدة';
    }
}
