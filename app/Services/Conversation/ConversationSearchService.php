<?php

namespace App\Services\Conversation;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ConversationSearchService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function paginate(User $user, ?string $search = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $builder = $user->conversations()
           ->getQuery()
            ->select(['id', 'title', 'pinned_at', 'created_at', 'updated_at'])
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

        $query->whereRaw(
            '"title" '.$operator." ? ESCAPE '!'",
            ['%'.$escaped.'%']
        );
    }
    
}
