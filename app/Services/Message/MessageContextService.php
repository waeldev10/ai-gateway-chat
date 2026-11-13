<?php

namespace App\Services\Message;

use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageContextService
{
    public function completedHistory(
        Conversation $conversation,
        ?Message $before = null,
        ?array $append = null,
    ): array {
        $maxMessages = max(1, (int) config('ai.context.max_messages', 25));
        $maxCharacters = max(1, (int) config('ai.context.max_characters', 24000));

        $appendCharacters = $append !== null
            ? Str::length($append['content'])
            : 0;

        if ($appendCharacters > $maxCharacters) {
            throw ValidationException::withMessages([
                'content' => 'رسالة المستخدم تتجاوز الحد الأقصى لسياق الذكاء الاصطناعي.',
            ]);
        }

        $availableMessages = $maxMessages - ($append !== null ? 1 : 0);
        $availableCharacters = $maxCharacters - $appendCharacters;

        $query = $conversation->messages()
            ->where('status', MessageStatus::Complete->value);

        if ($before !== null) {
            $createdAt = $before->created_at;

            $query->where(function (Builder $query) use ($before, $createdAt): void {
                if ($createdAt === null) {
                    $query->where('id', '<', $before->getKey());

                    return;
                }

                $query->where('created_at', '<', $createdAt)
                    ->orWhere(function (Builder $query) use ($before, $createdAt): void {
                        $query->where('created_at', $createdAt)
                            ->where('id', '<', $before->getKey());
                    });
            });
        }

        $messages = $availableMessages > 0
            ? $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($availableMessages)
                ->get(['role', 'content'])
            : collect();

        $selected = [];
        $characters = $appendCharacters;

        foreach ($messages as $message) {
            $length = Str::length($message->content);

            if ($length > $availableCharacters && $selected === []) {
                throw ValidationException::withMessages([
                    'conversation' => 'إحدى أحدث الرسائل تتجاوز الحد الأقصى لسياق الذكاء الاصطناعي.',
                ]);
            }

            if (count($selected) >= $availableMessages
                || $characters + $length > $maxCharacters) {
                break;
            }

            $selected[] = [
                'role' => $message->role->value,
                'content' => $message->content,
            ];

            $characters += $length;
        }

        $history = array_reverse($selected);

        if ($append !== null) {
            $history[] = $append;
        }

        return $history;
    }

    public function nextMessageAfter(
        Conversation $conversation,
        Message $message,
    ): ?Message {
        $createdAt = $message->created_at;

        return $conversation->messages()
            ->where(function (Builder $query) use ($message, $createdAt): void {
                if ($createdAt === null) {
                    $query->where('id', '>', $message->getKey());

                    return;
                }

                $query->where('created_at', '>', $createdAt)
                    ->orWhere(function (Builder $query) use ($message, $createdAt): void {
                        $query->where('created_at', $createdAt)
                            ->where('id', '>', $message->getKey());
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public function latestMessage(Conversation $conversation): ?Message
    {
        return $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
