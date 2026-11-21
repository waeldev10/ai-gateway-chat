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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class ConversationChat extends Component
{
    #[Locked]
    public ?string $conversationId = null;

    public string $draft = '';

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

        $rateLimitKey = 'ai-stream:'.$user->getKey();

        if (! RateLimiter::attempt($rateLimitKey, 20, fn (): bool => true, 60)) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تم تجاوز الحد',
                message: 'لقد أرسلت رسائل كثيرة خلال وقت قصير. حاول بعد دقيقة.',
            );

            return;
        }

        $isNew = $this->conversationId === null;

        $conversation = $isNew
            ? $conversations->create($user, [])
            : $conversations->findFor($user, (string) $this->conversationId);

        if (! $isNew) {
            $this->authorize('sendMessage', $conversation);
        }

        $this->conversationId = (string) $conversation->id;
        $titleBefore = $conversation->title;

        try {
            $stream = $messages->stream($conversation, $content, AiSource::Web);

            foreach ($stream as $delta) {
                $this->stream(e($delta), name: 'assistant-draft');
            }

            $result = $stream->getReturn();
        } catch (ConversationBusyException) {
            $this->removeEmptyNewConversation($isNew, $conversation);

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'المحادثة مشغولة',
                message: 'هناك عملية أخرى قيد التنفيذ. حاول بعد قليل.',
            );

            return;
        } catch (ValidationException $exception) {
            $this->removeEmptyNewConversation($isNew, $conversation);

            foreach ($exception->errors() as $field => $errors) {
                $this->addError($field === 'content' ? 'draft' : $field, (string) ($errors[0] ?? ''));
            }

            return;
        } catch (Throwable $exception) {
            report($exception);

            $this->removeEmptyNewConversation($isNew, $conversation);

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إرسال الرسالة',
                message: 'حدث خطأ غير متوقع. حاول مرة أخرى.',
            );

            return;
        }

        $this->draft = '';

        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        if (! $isNew) {
            $conversation->refresh();

            if (blank($titleBefore) && filled($conversation->title)) {
                $this->dispatch('conversation-renamed', id: (string) $conversation->id, title: (string) $conversation->title);
            }
        }

        if ($result['failed']) {
            $toast = [
                'type' => 'error',
                'title' => 'تعذر إكمال الرد',
                'message' => 'تم حفظ رسالتك، لكن تعذر توليد الرد بالكامل. حاول مرة أخرى.',
            ];

            if ($isNew) {
                session()->flash('toast', $toast);
            } else {
                $this->dispatch('toast', ...$toast);
            }
        }

        if ($isNew) {
            $this->redirectRoute('conversations.show', $conversation, navigate: true);
        }
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

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر تعديل الرسالة',
                message: 'حاول مرة أخرى.',
            );

            return false;
        }

        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        if ($result['failed']) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إكمال الرد',
                message: 'تم حفظ تعديلك، لكن تعذر توليد الرد بالكامل. حاول مرة أخرى.',
            );

            return false;
        }

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

            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إعادة التوليد',
                message: 'حاول مرة أخرى.',
            );

            return false;
        }

        $this->dispatch('conversations-changed');
        $this->dispatch('conversation-chat-updated', id: (string) $conversation->id);

        if ($result['failed']) {
            $this->dispatch(
                'toast',
                type: 'error',
                title: 'تعذر إكمال الرد',
                message: 'تم توليد جزء من الرد فقط. حاول مرة أخرى.',
            );

            return false;
        }

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

            $messages = $conversation->messages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        return view('livewire.chat.conversation-chat', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    private function removeEmptyNewConversation(bool $isNew, Conversation $conversation): void
    {
        if (! $isNew || $conversation->messages()->exists()) {
            return;
        }

        $deleted = rescue(fn (): ?bool => $conversation->delete(), false, report: false);

        if ($deleted) {
            $this->conversationId = null;
        }
    }
}
