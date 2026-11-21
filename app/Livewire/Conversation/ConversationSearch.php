<?php

namespace App\Livewire\Conversation;

use App\Services\Conversation\ConversationBulkService;
use App\Services\Conversation\ConversationSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class ConversationSearch extends Component
{
    public const PAGE_SIZE = ConversationSearchService::DEFAULT_PER_PAGE;

    #[Url(as: 'search')]
    public string $search = '';

    public int $perPage = self::PAGE_SIZE;

    public function mount(?string $search = null): void
    {
        $query = $search ?? request()->query('search');

        if (is_string($query)) {
            $this->search = mb_substr(trim($query), 0, 255);
        }
    }

    public function updatedSearch(): void
    {
        $this->perPage = self::PAGE_SIZE;
    }

    public function searchAction(): void {}

    public function clear(): void
    {
        $this->search = '';
        $this->perPage = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        $this->perPage = min(
            $this->perPage + self::PAGE_SIZE,
            ConversationSearchService::MAX_PER_PAGE,
        );
    }

    #[On('conversations-changed')]
    public function refreshResults(): void {}

    public function deleteConversations(array $ids, ConversationBulkService $bulk): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $user = Auth::user();

        abort_if($user === null, 403);

        if ($ids === []) {
            $this->dispatch('close-confirm-modal', id: 'search-delete');
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'لم يتم تحديد أي محادثة.');

            return;
        }

        try {
            $deleted = $bulk->deleteFor($user, $ids);
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->dispatch('close-confirm-modal', id: 'search-delete');
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'لا تملك صلاحية حذف إحدى المحادثات المحددة.');

            return;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('close-confirm-modal', id: 'search-delete');
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'حاول مرة أخرى.');

            return;
        }

        $this->dispatch('close-confirm-modal', id: 'search-delete');
        $this->dispatch('conversations-changed');
        $this->dispatch(
            'toast',
            type: 'success',
            title: $deleted > 1 ? 'تم حذف المحادثات' : 'تم حذف المحادثة',
            message: $deleted > 1
                ? 'تم حذف '.$deleted.' من المحادثات نهائياً.'
                : 'تم حذف المحادثة نهائياً.',
        );
    }

    public function placeholder(): string
    {
        return view('livewire.conversation.conversation-search-placeholder')->render();
    }

    #[Computed]
    public function results(): LengthAwarePaginator
    {
        $user = Auth::user();

        abort_if($user === null, 403);

        return app(ConversationSearchService::class)->paginate(
            $user,
            filled($this->search) ? $this->search : null,
            $this->perPage,
        );
    }

    #[Computed]
    public function groups(): array
    {
        return app(ConversationSearchService::class)->splitPinned($this->results->getCollection());
    }

    public function render(): View
    {
        return view('livewire.conversation.conversation-search', [
            'results' => $this->results,
            'groups' => $this->groups,
        ]);
    }
}
