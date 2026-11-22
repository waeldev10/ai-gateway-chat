<?php

namespace App\Livewire\Chat;

use App\Enums\AiSource;
use App\Enums\MessageRole;
use App\Exceptions\ConversationBusyException;
use App\Http\Requests\Api\V1\Conversations\SendMessageRequest;
use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use App\Services\Message\MessageContextService;
use App\Services\Message\MessageEditingService;
use App\Services\Message\MessageGenerationService;
use App\Services\Message\MessageRegenerationService;
use App\Services\Message\MessageService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** يدير حالة واجهة المحادثة، ويحمل الرسائل على دفعات ويفوض الإرسال والتحرير والتوليد إلى الخدمات. */
class ConversationChat extends Component
{
    #[Locked]
    public ?string $conversationId = null;

    #[Locked]
    public array $loadedMessageIds = [];

    #[Locked]
    public ?string $olderMessagesCreatedAt = null;

    #[Locked]
    public ?string $olderMessagesId = null;

    #[Locked]
    public bool $hasOlderMessages = false;

    #[Locked]
    public bool $shouldSendInitialMessage = false;

    #[Locked]
    public bool $isInitialSendInProgress = false;

    #[Locked]
    public ?string $initialSendContent = null;

    #[Locked]
    public bool $rateLimitAlreadyChecked = false;

    public string $draft = '';

    private const int MESSAGES_PER_PAGE = 20;

    public function mount(ConversationService $conversations, ?string $conversationId = null): void
    {
        $this->conversationId = $conversationId;

        if ($conversationId === null) {
            return;
        }

        $user = Auth::user();
        abort_if($user === null, 403);

        $conversation = $conversations->findFor($user, $conversationId);
        $this->authorize('view', $conversation);
        $this->conversationId = (string) $conversation->id;
        $this->loadLatestMessages($conversation);

        $initialSend = session()->pull('conversation-initial-send');

        if (is_array($initialSend)
            && ($initialSend['conversation_id'] ?? null) === $this->conversationId
            && is_string($initialSend['content'] ?? null)) {
            $this->initialSendContent = $initialSend['content'];
            $this->draft = $initialSend['content'];
            $this->rateLimitAlreadyChecked = (bool) ($initialSend['rate_limit_checked'] ?? false);
            $this->shouldSendInitialMessage = true;
        }
    }

    public function sendInitialMessage(MessageService $messages, ConversationService $conversations): void
    {
        if (! $this->shouldSendInitialMessage || $this->initialSendContent === null) {
            return;
        }

        $this->isInitialSendInProgress = true;
        $this->draft = $this->initialSendContent;
        $this->initialSendContent = null;
        $this->shouldSendInitialMessage = false;

        try {
            $this->send($messages, $conversations);
        } finally {
            $this->isInitialSendInProgress = false;
        }
    }

    public function loadOlderMessages(): void
    {
        if (! $this->hasOlderMessages || $this->olderMessagesCreatedAt === null || $this->olderMessagesId === null) {
            return;
        }

        $user = Auth::user();
        abort_if($user === null, 403);
        abort_if($this->conversationId === null, 404);

        $conversation = app(ConversationService::class)->findFor($user, $this->conversationId);
        $this->authorize('view', $conversation);

        $olderMessages = $conversation->messages()
            ->where(function (Builder $query): void {
                $query->where('created_at', '<', $this->olderMessagesCreatedAt)
                    ->orWhere(function (Builder $query): void {
                        $query->where('created_at', '=', $this->olderMessagesCreatedAt)
                            ->where('id', '<', $this->olderMessagesId);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        $this->hasOlderMessages = $olderMessages->count() > self::MESSAGES_PER_PAGE;
        $olderMessages = $olderMessages->take(self::MESSAGES_PER_PAGE)->reverse()->values();

        if ($olderMessages->isEmpty()) {
            $this->hasOlderMessages = false;

            return;
        }

        $oldestMessage = $olderMessages->first();
        $this->olderMessagesCreatedAt = $oldestMessage->created_at->toDateTimeString();
        $this->olderMessagesId = (string) $oldestMessage->id;
        $this->loadedMessageIds = array_values(array_unique([
            ...$olderMessages->map(fn ($message): string => (string) $message->id)->all(),
            ...$this->loadedMessageIds,
        ]));
    }

    public function send(MessageService $messages, ConversationService $conversations): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        $content = trim($this->draft);

        $validator = Validator::make(
            ['content' => $content],
            (new SendMessageRequest)->rules(),
        );

        if ($validator->fails()) {
            $this->addError('draft', (string) $validator->errors()->first('content'));

            return;
        }

        $rateLimitAlreadyChecked = $this->rateLimitAlreadyChecked;
        $this->rateLimitAlreadyChecked = false;
        $rateLimitKey = 'ai-stream:'.$user->getKey();

        if (! $rateLimitAlreadyChecked && ! RateLimiter::attempt($rateLimitKey, 20, fn (): bool => true, 60)) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تم تجاوز الحد',
                message: 'لقد أرسلت رسائل كثيرة خلال وقت قصير. حاول بعد دقيقة.',
            );

            return;
        }

        if ($this->conversationId === null) {
            $conversation = $conversations->create($user, []);
            $this->conversationId = (string) $conversation->id;
            $this->isInitialSendInProgress = true;

            session()->flash('conversation-initial-send', [
                'conversation_id' => (string) $conversation->id,
                'content' => $content,
                'rate_limit_checked' => true,
            ]);

            $this->redirectRoute('conversations.show', $conversation, navigate: true);

            return;
        }

        $conversation = $conversations->findFor($user, (string) $this->conversationId);
        $this->authorize('sendMessage', $conversation);
        $this->conversationId = (string) $conversation->id;
        $titleBefore = $conversation->title;

        try {
            $stream = $messages->stream($conversation, $content, AiSource::Web);

            foreach ($stream as $delta) {
                $this->stream(e($delta), name: 'assistant-draft');
            }

            $result = $stream->getReturn();
        } catch (ConversationBusyException) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'المحادثة مشغولة',
                message: 'هناك عملية أخرى قيد التنفيذ. حاول بعد قليل.',
            );

            return;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $errors) {
                $this->addError($field === 'content' ? 'draft' : $field, (string) ($errors[0] ?? ''));
            }

            return;
        } catch (Throwable $exception) {
            report($exception);

            $this->refreshVisibleMessages($conversation);

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إرسال الرسالة',
                message: 'حدث خطأ غير متوقع. حاول مرة أخرى.',
            );

            return;
        }

        $this->refreshVisibleMessages($conversation);
        $this->draft = '';

        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        $conversation->refresh();

        if (blank($titleBefore) && filled($conversation->title)) {
            $this->dispatch('conversation-renamed', id: (string) $conversation->id, title: (string) $conversation->title);
        }

        if ($result['failed']) {
            if ($result['connection_failed'] ?? false) {
                $this->dispatch('ai-provider-connection-failed');

                return;
            }

            $toast = [
                'type' => 'error',
                'title' => 'تعذر إكمال الرد',
                'message' => 'تم حفظ رسالتك، لكن تعذر توليد الرد بالكامل. حاول مرة أخرى.',
            ];

            $this->dispatch('toast', ...$toast);

            return;
        }

        $this->dispatch('ai-provider-connection-restored');
    }

    public function editMessage(MessageEditingService $editing, string $messageId, string $content): bool
    {
        $user = Auth::user();
        abort_if($user === null, 403);
        abort_if($this->conversationId === null, 404);

        $content = trim($content);

        Validator::make(
            ['content' => $content],
            (new SendMessageRequest)->rules(),
        )->validate();

        $conversation = app(ConversationService::class)->findFor($user, (string) $this->conversationId);
        $this->authorize('sendMessage', $conversation);

        $message = $conversation->messages()->whereKey($messageId)->firstOrFail();

        // Stream into the assistant reply that follows the edited user message.
        // If there is no assistant reply yet, stream into the new-assistant slot.
        $nextMessage = app(MessageContextService::class)->nextMessageAfter($conversation, $message);
        $streamName = ($nextMessage !== null && $nextMessage->role === MessageRole::Assistant)
            ? 'assistant-edit-'.(string) $nextMessage->getKey()
            : 'assistant-edit-new';

        try {
            $stream = $editing->stream($conversation, $message, $content, AiSource::Web, branch: true);

            foreach ($stream as $delta) {
                $this->stream(e($delta), name: $streamName);
            }

            $result = $stream->getReturn();
        } catch (ConversationBusyException) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'المحادثة مشغولة',
                message: 'هناك عملية أخرى قيد التنفيذ. حاول بعد قليل.',
            );

            return false;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            report($e);

            $this->refreshVisibleMessages($conversation);

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر تعديل الرسالة',
                message: 'حاول مرة أخرى.',
            );

            return false;
        }

        $this->refreshVisibleMessages($conversation);
        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        if ($result['failed']) {
            if ($result['connection_failed'] ?? false) {
                $this->dispatch('ai-provider-connection-failed');

                return false;
            }

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إكمال الرد',
                message: 'تم حفظ تعديلك، لكن تعذر توليد الرد بالكامل. حاول مرة أخرى.',
            );

            return false;
        }

        $this->dispatch('ai-provider-connection-restored');

        return true;
    }

    public function regenerateMessage(MessageRegenerationService $regenerating, string $messageId): bool
    {
        $user = Auth::user();
        abort_if($user === null, 403);
        abort_if($this->conversationId === null, 404);

        $conversation = app(ConversationService::class)->findFor($user, (string) $this->conversationId);
        $this->authorize('sendMessage', $conversation);

        $message = $conversation->messages()->whereKey($messageId)->firstOrFail();

        try {
            $stream = $regenerating->stream($conversation, $message, AiSource::Web, branch: true);

            foreach ($stream as $delta) {
                $this->stream(e($delta), name: 'assistant-regeneration-'.$message->id);
            }

            $result = $stream->getReturn();
        } catch (ConversationBusyException) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'المحادثة مشغولة',
                message: 'هناك عملية أخرى قيد التنفيذ. حاول بعد قليل.',
            );

            return false;
        } catch (ValidationException $exception) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إعادة التوليد',
                message: (string) (collect($exception->errors())->flatten()->first() ?? 'حاول مرة أخرى.'),
            );

            return false;
        } catch (Throwable $e) {
            report($e);

            $this->refreshVisibleMessages($conversation);

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إعادة التوليد',
                message: 'حاول مرة أخرى.',
            );

            return false;
        }

        $this->refreshVisibleMessages($conversation);
        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        if ($result['failed']) {
            if ($result['connection_failed'] ?? false) {
                $this->dispatch('ai-provider-connection-failed');

                return false;
            }

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إكمال الرد',
                message: 'تم توليد جزء من الرد فقط. حاول مرة أخرى.',
            );

            return false;
        }

        $this->dispatch('ai-provider-connection-restored');

        return true;
    }

    public function stopGeneration(string $conversationId): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        $conversation = app(ConversationService::class)->findFor($user, $conversationId);
        $this->authorize('view', $conversation);

        app(MessageGenerationService::class)->stop($conversation);

        $this->dispatch('generation-stopped', id: $conversationId);
    }

    public function render(ConversationService $conversations): View
    {
        $conversation = null;
        $messages = collect();

        if ($this->conversationId !== null) {
            $user = Auth::user();
            abort_if($user === null, 403);

            $conversation = $conversations->findFor($user, $this->conversationId);
            $this->authorize('view', $conversation);

            if ($this->loadedMessageIds !== []) {
                $messages = $conversation->messages()
                    ->whereIn('id', $this->loadedMessageIds)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();
            }
        }

        return view('livewire.chat.conversation-chat', [
            'conversation' => $conversation,
            'messages' => $messages,
            'hasMessages' => $messages->isNotEmpty() || $this->shouldSendInitialMessage || $this->isInitialSendInProgress,
            'hasOlderMessages' => $this->hasOlderMessages,
            'initialSendContent' => $this->initialSendContent,
            'shouldSendInitialMessage' => $this->shouldSendInitialMessage,
        ]);
    }

    private function loadLatestMessages(Conversation $conversation): void
    {
        $latestMessages = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE + 1)
            ->get();

        $this->hasOlderMessages = $latestMessages->count() > self::MESSAGES_PER_PAGE;
        $latestMessages = $latestMessages->take(self::MESSAGES_PER_PAGE)->reverse()->values();

        $this->loadedMessageIds = $latestMessages
            ->map(fn ($message): string => (string) $message->id)
            ->all();

        $oldestMessage = $latestMessages->first();
        $this->olderMessagesCreatedAt = $oldestMessage?->created_at->toDateTimeString();
        $this->olderMessagesId = $oldestMessage === null ? null : (string) $oldestMessage->id;
    }

    private function refreshVisibleMessages(Conversation $conversation): void
    {
        if ($this->loadedMessageIds === []) {
            $this->loadLatestMessages($conversation);

            return;
        }

        $latestMessageIds = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->reverse()
            ->values()
            ->all();

        $this->loadedMessageIds = array_values(array_unique([
            ...$this->loadedMessageIds,
            ...$latestMessageIds,
        ]));
    }
}
