<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** يبني قوائم المحادثات المثبتة والحديثة ونتائج البحث الخاصة بالمستخدم */
class ConversationSearchService
{
    public const SIDEBAR_LIMIT = 10;

    public const SIDEBAR_PINNED_LIMIT = self::SIDEBAR_LIMIT;

    public const SIDEBAR_RECENT_LIMIT = self::SIDEBAR_LIMIT;

    public const DEFAULT_PER_PAGE = 10;

    public const MAX_PER_PAGE = 100;

    private const SIDEBAR_COLUMNS = ['id', 'title', 'pinned_at', 'updated_at'];

    public function sidebarFor(User $user, ?string $activeId = null): array
    {
        $items = $user->conversations()
            ->select(self::SIDEBAR_COLUMNS)
            ->orderByRaw('pinned_at IS NULL')
            ->orderByDesc('pinned_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::SIDEBAR_LIMIT + 1)
            ->get();

        $hasMore = $items->count() > self::SIDEBAR_LIMIT;

        $items = $items->take(self::SIDEBAR_LIMIT)->values();

        if (filled($activeId) && ! $items->contains('id', $activeId)) {
            $active = $user->conversations()
                ->select(self::SIDEBAR_COLUMNS)
                ->find($activeId);

            if ($active !== null) {
                $items->push($active);
            }
        }

        return [
            ...$this->splitPinned($items),
            'hasMore' => $hasMore,
        ];
    }

    public function splitPinned(iterable $conversations): array
    {
        $items = $conversations instanceof Collection ? $conversations : collect($conversations);

        [$pinned, $recent] = $items->partition(
            fn (Conversation $conversation): bool => $conversation->pinned_at !== null
        );

        return [
            'pinned' => $pinned->values(),
            'recent' => $recent->values(),
        ];
    }

    public function paginate(User $user, ?string $search = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $builder = $user->conversations()
            ->getQuery()
            ->select(['id', 'title', 'pinned_at', 'created_at', 'updated_at'])
            ->orderByRaw('pinned_at IS NULL')
            ->orderByDesc('pinned_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $term = trim((string) $search);

        if ($term !== '') {
            $this->applyTitleSearch($builder, $term);
        }

        return $builder->paginate($perPage)->withQueryString();
    }

    private function applyTitleSearch(Builder $query, string $term): void
    {
        $escaped = str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $term
        );

        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        $column = $query->getQuery()->getGrammar()->wrap('title');

        $query->whereRaw(
            $column.' '.$operator." ? ESCAPE '!'",
            ['%'.$escaped.'%']
        );
    }
}
