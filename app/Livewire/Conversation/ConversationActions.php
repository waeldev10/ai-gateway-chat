<?php

namespace App\Livewire\Conversation;

use App\Http\Requests\Api\V1\Conversations\UpdateConversationRequest;
use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class ConversationActions extends Component
{
    #[Locked]
    public ?string $conversationId = null;

    #[Locked]
    public string $context = 'page';

    #[Locked]
    public ?string $activeId = null;

    #[Locked]
    public string $title = '';

    #[Locked]
    public bool $isPinned = false;

    public function mount(
        ConversationService $conversations,
        ?string $conversationId = null,
        string $context = 'page',
        ?string $activeId = null,
    ): void {
        $this->conversationId = $conversationId;
        $this->context = $context;
        $this->activeId = $activeId;

        if ($conversationId === null) {
            return;
        }

        $user = Auth::user();

        abort_if($user === null, 403);

        $conversation = $conversations->findFor($user, $conversationId);
        $this->authorize('view', $conversation);

        $this->conversationId = (string) $conversation->id;
        $this->activeId ??= (string) $conversation->id;
        $this->title = (string) $conversation->title;
        $this->isPinned = $conversation->pinned_at !== null;
    }

    /**
     * Only fixed-conversation instances need to mirror state changed elsewhere.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->conversationId === null) {
            return [];
        }

        return [
            'conversation-pin-changed' => 'syncPinState',
            'conversation-renamed' => 'syncTitle',
        ];
    }

    public function togglePin(ConversationService $conversations, ?string $id = null): void
    {
        $id = $this->resolveConversationId($id);
        $conversation = $this->ownedConversation($id, $conversations);
        $this->authorize('update', $conversation);

        try {
            $conversation = $conversations->togglePin($conversation);
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('toast', type: 'error', title: 'تعذر تحديث التثبيت', message: 'حاول مرة أخرى.');

            return;
        }

        $isPinned = $conversation->pinned_at !== null;

        if ($id === $this->conversationId) {
            $this->isPinned = $isPinned;
        }

        $this->dispatch('conversations-changed');
        $this->dispatch(
            'conversation-pin-changed',
            id: (string) $conversation->id,
            pinned: $isPinned,
            source: $this->context,
        );
        $this->dispatch(
            'toast',
            type: 'success',
            title: $isPinned ? 'تم تثبيت المحادثة' : 'تم إلغاء تثبيت المحادثة',
            message: filled($conversation->title) ? (string) $conversation->title : 'محادثة جديدة',
        );
    }

    public function rename(ConversationService $conversations, ?string $id = null, string $title = ''): void
    {
        $validator = Validator::make(
            ['title' => $title],
            (new UpdateConversationRequest)->rules(),
        );

        if ($validator->fails()) {
            $message = (string) $validator->errors()->first('title');

            $this->dispatch('conversation-rename-failed', scope: $this->context, message: $message);
            $this->dispatch('toast', type: 'error', title: 'تعذر حفظ الاسم', message: $message);

            return;
        }

        $id = $this->resolveConversationId($id);
        $conversation = $this->ownedConversation($id, $conversations);
        $this->authorize('update', $conversation);

        try {
            $conversation = $conversations->rename($conversation, $validator->validated()['title']);
        } catch (Throwable $e) {
            report($e);

            $message = 'تعذر حفظ الاسم. حاول مرة أخرى.';

            $this->dispatch('conversation-rename-failed', scope: $this->context, message: $message);
            $this->dispatch('toast', type: 'error', title: 'تعذر حفظ الاسم', message: $message);

            return;
        }

        $this->title = (string) $conversation->title;

        $this->dispatch('conversation-rename-succeeded', scope: $this->context);
        $this->dispatch('conversations-changed');

        if ($this->isOpenConversation((string) $conversation->id)) {
            $this->dispatch(
                'conversation-renamed',
                id: (string) $conversation->id,
                title: $this->title,
                source: $this->context,
            );
        }

        $this->dispatch(
            'toast',
            type: 'success',
            title: 'تمت إعادة تسمية المحادثة',
            message: $this->title,
        );
    }

    public function delete(ConversationService $conversations, ?string $id = null, bool $isActive = false): void
    {
        $id = $this->resolveConversationId($id);
        $user = Auth::user();

        abort_if($user === null, 403);

        try {
            $conversation = $conversations->findFor($user, $id);
            $this->authorize('delete', $conversation);
            $conversations->delete($conversation);
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->dispatch('close-confirm-modal', id: $this->deleteModalId());
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'لا تملك صلاحية حذف هذه المحادثة.');

            return;
        } catch (Throwable $e) {
            report($e);

            $this->dispatch('close-confirm-modal', id: $this->deleteModalId());
            $this->dispatch('toast', type: 'error', title: 'تعذر الحذف', message: 'حاول مرة أخرى.');

            return;
        }

        $this->dispatch('close-confirm-modal', id: $this->deleteModalId());

        if ($isActive || $this->isOpenConversation($id)) {
            session()->flash('toast', [
                'type' => 'success',
                'title' => 'تم حذف المحادثة',
                'message' => 'تم حذف المحادثة نهائياً.',
            ]);

            $this->redirectRoute('conversations.create');

            return;
        }

        $this->dispatch('conversations-changed');
        $this->dispatch(
            'toast',
            type: 'success',
            title: 'تم حذف المحادثة',
            message: 'تم حذف المحادثة نهائياً.',
        );
    }

    public function syncPinState(string $id, bool $pinned, ?string $source = null): void
    {
        if ($this->conversationId === null || $id !== $this->conversationId) {
            return;
        }

        if ($source === $this->context) {
            return;
        }

        $this->isPinned = $pinned;
    }

    public function syncTitle(string $id, string $title, ?string $source = null): void
    {
        if ($this->conversationId === null || $id !== $this->conversationId) {
            return;
        }

        if ($source === $this->context) {
            return;
        }

        $this->title = $title;
    }

    public function render(): View
    {
        return view('livewire.conversation.conversation-actions', [
            'url' => $this->conversationId !== null
                ? route('conversations.show', $this->conversationId)
                : null,
            'deleteModalId' => $this->deleteModalId(),
        ]);
    }

    private function resolveConversationId(?string $id): string
    {
        $id ??= $this->conversationId;

        abort_if($id === null || $id === '', 404);

        return $id;
    }

    private function isOpenConversation(string $id): bool
    {
        return $this->activeId !== null && $this->activeId === $id;
    }

    private function deleteModalId(): string
    {
        return $this->context === 'search' ? 'search-item-delete' : $this->context.'-delete';
    }

    private function ownedConversation(string $id, ConversationService $conversations): Conversation
    {
        $user = Auth::user();

        abort_if($user === null, 403);

        return $conversations->findFor($user, $id);
    }
}
