<?php

namespace App\Livewire\Sidebar;

use App\Services\Conversation\ConversationSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarConversations extends Component
{
    #[Locked]
    public ?string $activeId = null;

    public function mount(?string $activeId = null): void
    {
        $this->activeId = $activeId ?? request()->route('conversation')?->id;
    }

    public function placeholder(): string
    {
        return view('livewire.sidebar.sidebar-conversations-placeholder')->render();
    }

    #[On('conversations-changed')]
    public function refreshList(): void {}

    #[Computed]
    public function groups(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return ['pinned' => collect(), 'recent' => collect(), 'hasMore' => false];
        }

        return app(ConversationSearchService::class)->sidebarFor($user, $this->activeId);
    }

    public function render(): View
    {
        return view('livewire.sidebar.sidebar-conversations', [
            'groups' => $this->groups,
        ]);
    }
}
