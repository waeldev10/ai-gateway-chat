<?php

namespace App\Services\Message;

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageRegenerationService
{
    public function __construct(
        private readonly MessageContextService $contextService,
        private readonly MessageGenerationService $generationService,
        private readonly ConversationOperationLock $operationLock,
    ) {}

    public function regenerate(
        Conversation $conversation,
        Message $message,
        AiSource $source = AiSource::Api,
        ?AiPersona $persona = null,
    ): Message {
        return $this->operationLock->run($conversation, function () use ($conversation, $message, $source, $persona): Message {
            [$history, $replace] = DB::transaction(function () use ($conversation, $message): array {
                $lockedConversation = $this->operationLock->lockForUpdate($conversation);

                $target = $lockedConversation->messages()
                    ->whereKey($message->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->generationPlan($lockedConversation, $target);
            });

            if ($history === [] || ! $this->containsUserMessage($history)) {
                throw ValidationException::withMessages([
                    'message' => 'لا توجد رسائل مكتملة كافية لإعادة توليد الرد.',
                ]);
            }

            return $this->generationService->generate($conversation, $history, $source, $replace, $persona);
        });
    }

    private function generationPlan(Conversation $conversation, Message $message): array
    {
        if ($message->role === MessageRole::Assistant) {
            return [
                $this->contextService->completedHistory($conversation, $message),
                $message,
            ];
        }

        if ($message->role !== MessageRole::User) {
            throw new AuthorizationException('Only user and assistant messages can be regenerated.');
        }

        if ($message->status !== MessageStatus::Complete) {
            throw ValidationException::withMessages([
                'message' => 'لا يمكن إعادة توليد الرد لرسالة مستخدم غير مكتملة.',
            ]);
        }

        $nextMessage = $this->contextService->nextMessageAfter($conversation, $message);

        if ($nextMessage !== null && $nextMessage->role !== MessageRole::Assistant) {
            throw ValidationException::withMessages([
                'message' => 'لا يمكن إعادة توليد الرد لأن الرسالة متبوعة برسالة أخرى.',
            ]);
        }

        $history = $this->contextService->completedHistory(
            $conversation,
            $message,
            [
                'role' => MessageRole::User->value,
                'content' => $message->content,
            ],
        );

        return [$history, $nextMessage];
    }

    private function containsUserMessage(array $history): bool
    {
        foreach ($history as $message) {
            if ($message['role'] === MessageRole::User->value) {
                return true;
            }
        }

        return false;
    }
}
