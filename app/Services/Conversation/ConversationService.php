<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Services\Message\ConversationOperationLock;

class ConversationService
{
    public function __construct(
        private readonly ConversationOperationLock $operationLock = new ConversationOperationLock()
    ) {}

    public function create(User $user, array $data): Conversation
    {
        return DB::transaction(fn (): Conversation => $user->conversations()->create([
            'title' => $data['title'] ?? null,
        ]));
    }

    public function show(Conversation $conversation): Conversation
    {
        return $conversation;
    }

    public function rename(Conversation $conversation, string $title): Conversation
    {
        $conversation->update(['title' => $title]);

        return $conversation;
    }

    public function delete(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $this->operationLock->run($conversation, function () use ($conversation): void {
                $this->operationLock->lockForUpdate($conversation)->delete();
            });
        });
    }

    public function pin(Conversation $conversation): Conversation
    {
        if ($conversation->pinned_at === null) {
            $conversation->update(['pinned_at' => now()]);
        }

        return $conversation;
    }

    public function unpin(Conversation $conversation): Conversation
    {
        if ($conversation->pinned_at !== null) {
            $conversation->update(['pinned_at' => null]);
        }

        return $conversation;
    }
}
