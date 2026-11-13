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

class MessageEditingService
{
    public function __construct(
        private readonly MessageContextService $contextService,
        private readonly MessageGenerationService $generationService,
        private readonly ConversationOperationLock $operationLock,
    ) {}

    public function edit(
        Conversation $conversation,
        Message $message,
        string $content,
        AiSource $source = AiSource::Api,
        ?AiPersona $persona = null,
    ): array {
        return $this->operationLock->run($conversation, function () use ($conversation, $message, $content, $source, $persona): array {
            [$history, $target, $replace] = DB::transaction(function () use ($conversation, $message, $content): array {
                $lockedConversation = $this->operationLock->lockForUpdate($conversation);

                $target = $lockedConversation->messages()
                    ->whereKey($message->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($target->role !== MessageRole::User) {
                    throw new AuthorizationException('Only user messages can be edited.');
                }

                $nextMessage = $this->contextService->nextMessageAfter($lockedConversation, $target);

                if ($nextMessage !== null && $nextMessage->role !== MessageRole::Assistant) {
                    throw ValidationException::withMessages([
                        'message' => 'لا يمكن تعديل الرسالة لأنها متبوعة برسالة أخرى.',
                    ]);
                }

                $history = $this->contextService->completedHistory(
                    $lockedConversation,
                    $target,
                    [
                        'role' => MessageRole::User->value,
                        'content' => $content,
                    ],
                );

                return [$history, $target, $nextMessage];
            });

            $attempt = $this->generationService->attempt($conversation, $history, $source, $persona);

            $assistantMessage = $this->generationService->persist(
                $attempt,
                $conversation,
                $replace,
                function () use ($target, $content): void {
                    $target->update([
                        'content' => $content,
                        'status' => MessageStatus::Complete,
                    ]);
                },
            );

            return [
                'user_message' => $target,
                'assistant_message' => $assistantMessage,
            ];
        });
    }
}
