<?php

namespace App\Services\Message;

use App\Exceptions\ConversationBusyException;
use App\Models\Conversation;
use Closure;
use Illuminate\Support\Facades\Cache;

final class ConversationOperationLock
{
    /**
     * Run a conversation mutation while holding its operation lock.
     *
     * @param  Closure(): mixed  $callback
     */
    public function run(Conversation $conversation, Closure $callback): mixed
    {
        return $this->runMany([$conversation], $callback);
    }

    /**
     * Run a mutation while holding the operation lock of every conversation.
     *
     * Locks are acquired in a stable order so overlapping bulk operations
     * cannot leave locks behind when one of them is already busy.
     *
     * @param  array<int, Conversation>  $conversations
     * @param  Closure(): mixed  $callback
     */
    public function runMany(array $conversations, Closure $callback): mixed
    {
        $locks = [];

        try {
            foreach ($this->uniqueKeys($conversations) as $key) {
                $lock = Cache::lock($key, $this->ttlSeconds());

                if (! $lock->get()) {
                    throw ConversationBusyException::operationInProgress();
                }

                $locks[] = $lock;
            }

            return $callback();
        } finally {
            foreach ($locks as $lock) {
                rescue(fn () => $lock->release(), report: false);
            }
        }
    }

    /**
     * Refresh and lock the conversation row inside the current transaction.
     */
    public function lockForUpdate(Conversation $conversation): Conversation
    {
        $locked = Conversation::query()
            ->whereKey($conversation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $conversation->setRawAttributes($locked->getAttributes(), true);

        return $locked;
    }

    public static function key(Conversation $conversation): string
    {
        return 'message-operation:'.$conversation->getKey();
    }

    /**
     * @param  array<int, Conversation>  $conversations
     * @return array<int, string>
     */
    private function uniqueKeys(array $conversations): array
    {
        $keys = [];

        foreach ($conversations as $conversation) {
            $keys[self::key($conversation)] = true;
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    private function ttlSeconds(): int
    {
        $connection = config('ai.default');
        $timeout = is_string($connection)
            ? (int) config("ai.connections.{$connection}.timeout", 60)
            : 60;

        return max(60, $timeout + 30);
    }
}
