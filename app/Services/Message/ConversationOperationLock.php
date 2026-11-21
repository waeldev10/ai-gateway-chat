<?php

namespace App\Services\Message;

use App\Exceptions\ConversationBusyException;
use App\Models\Conversation;
use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;

final class ConversationOperationLock
{
    public function run(Conversation $conversation, Closure $callback): mixed
    {
        return $this->runMany([$conversation], $callback);
    }

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
     * Hold the conversation lock for as long as the returned generator is
     * being consumed. The lock is acquired on first iteration and released
     * when the generator finishes or is destroyed.
     *
     * @param  Closure(): Generator<mixed, mixed, mixed, mixed>  $callback
     * @return Generator<mixed, mixed, mixed, mixed>
     */
    public function runGenerator(Conversation $conversation, Closure $callback): Generator
    {
        $lock = Cache::lock(self::key($conversation), $this->ttlSeconds());

        if (! $lock->get()) {
            throw ConversationBusyException::operationInProgress();
        }

        try {
            return yield from $callback();
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

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
