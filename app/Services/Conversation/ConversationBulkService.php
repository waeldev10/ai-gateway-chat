<?php

namespace App\Services\Conversation;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use App\Services\Message\ConversationOperationLock;

class ConversationBulkService
{
    public function __construct(
        private readonly ConversationOperationLock $operationLock = new ConversationOperationLock()
    ) {}

    public function deleteFor(User $user, array $ids): int
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($user, $ids): int {
            $conversations = $user->conversations()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(['id']);

            if ($conversations->count() !== count($ids)) {
                throw new AuthorizationException(
                    'One or more conversations do not belong to the user.'
                );
            }

            return $this->operationLock->runMany(
                $conversations->all(),
                function () use ($user, $ids): int {
                    $ownedIds = $user->conversations()
                        ->whereIn('id', $ids)
                        ->lockForUpdate()
                        ->pluck('id')
                        ->all();

                    if (count($ownedIds) !== count($ids)) {
                        throw new AuthorizationException(
                            'One or more conversations do not belong to the user.'
                        );
                    }

                    return $user->conversations()
                        ->whereIn('id', $ids)
                        ->delete();
                }
            );
        });
    }
}
