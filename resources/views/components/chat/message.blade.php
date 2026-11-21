@props(['message', 'nextId' => null])

@php
    $isUser = $message->role === \App\Enums\MessageRole::User;
    $isFailed = $message->status === \App\Enums\MessageStatus::Failed;
    $html = $isUser ? null : \Illuminate\Support\Str::markdown(
        (string) $message->content,
        ['html_input' => 'escape', 'allow_unsafe_links' => false],
        [new \App\Support\Markdown\CodeBlockExtension],
    );
    $messageId = (string) $message->id;
    $editStreamTarget = $nextId !== null ? 'assistant-edit-'.$nextId : 'assistant-edit-new';
@endphp

<div
    wire:key="message-{{ $message->id }}"
    data-message
    data-message-id="{{ $messageId }}"
    data-message-content="{{ $message->content }}"
    data-edit-stream-target="{{ $editStreamTarget }}"
    @edit-stream-started.window="if ($event.detail.target === 'assistant-edit-{{ $messageId }}') streamingEdit = true"
    @edit-stream-ended.window="if ($event.detail.target === 'assistant-edit-{{ $messageId }}') streamingEdit = false"
    x-data="{
        editing: false,
        saving: false,
        regenerating: false,
        streamingEdit: false,
        editContent: '',
        originalContent: '',
        editError: '',
        showServerError: false,
        init() {
            this.originalContent = this.messageContent();
            this.editContent = this.originalContent;
        },
        messageContent() {
            return this.$el.dataset.messageContent
                || this.$el.querySelector('[data-user-message-content]')?.textContent
                || '';
        },
        startEdit() {
            const content = this.messageContent() || this.$refs.editTextarea?.value || this.originalContent;
            this.originalContent = content;
            this.editContent = content;

            if (this.$refs.editTextarea) {
                this.$refs.editTextarea.value = content;
            }

            this.editError = '';
            this.showServerError = false;
            this.editing = true;

            this.$nextTick(() => {
                this.$refs.editTextarea?.focus();
            });
        },
        cancelEdit() {
            const content = this.messageContent() || this.originalContent;
            this.editContent = content;

            if (this.$refs.editTextarea) {
                this.$refs.editTextarea.value = content;
            }

            this.editing = false;
            this.editError = '';
            this.showServerError = false;
        },
        async saveEdit() {
            const content = this.editContent.trim();

            if (this.saving || content === '') {
                return;
            }

            this.saving = true;
            this.editError = '';
            this.showServerError = true;

            const target = this.$el.dataset.editStreamTarget || '';
            window.dispatchEvent(new CustomEvent('generation-started'));
            window.dispatchEvent(new CustomEvent('edit-stream-started', { detail: { target } }));

            try {
                const saved = await this.$wire.editMessage('{{ $messageId }}', content);

                if (saved) {
                    this.originalContent = content;
                    this.editing = false;
                }
            } catch (error) {
                const validationMessage = error?.errors?.content?.[0]
                    || error?.errors?.conversation?.[0]
                    || error?.errors?.message?.[0]
                    || error?.errors?.edit?.[0];

                this.editError = validationMessage ? '' : 'تعذر حفظ التعديل.';
            } finally {
                this.saving = false;
                window.dispatchEvent(new CustomEvent('edit-stream-ended', { detail: { target } }));
                window.dispatchEvent(new CustomEvent('generation-ended'));
            }
        },
        async regenerateMessage() {
            if (this.regenerating) {
                return;
            }

            this.regenerating = true;
            this.$el.querySelector('[data-stream-target]')?.replaceChildren();

            window.dispatchEvent(new CustomEvent('generation-started'));

            try {
                await this.$wire.regenerateMessage('{{ $messageId }}');
            } catch (error) {
                this.$dispatch('toast', {
                    type: 'error',
                    title: 'تعذر إعادة التوليد',
                    message: 'حاول مرة أخرى.',
                });
            } finally {
                this.regenerating = false;
                window.dispatchEvent(new CustomEvent('generation-ended'));
            }
        },
        async copyMessage() {
            const text = this.$el.dataset.messageContent || '';

            try {
                if (! await this.writeClipboard(text)) {
                    throw new Error('copy-failed');
                }

                this.$dispatch('toast', {
                    type: 'success',
                    title: 'تم النسخ',
                    message: 'تم نسخ الرسالة إلى الحافظة.',
                });
            } catch (error) {
                this.$dispatch('toast', {
                    type: 'error',
                    title: 'تعذر النسخ',
                    message: 'حاول مرة أخرى.',
                });
            }
        },
        async writeClipboard(text) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);

                    return true;
                }
            } catch (error) {
            }

            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                return document.execCommand('copy');
            } catch (error) {
                return false;
            } finally {
                document.body.removeChild(textarea);
            }
        },
        async shareMessage() {
            const text = this.$el.dataset.messageContent || '';
            const url = window.location.href;

            try {
                if (navigator.share) {
                    await navigator.share({ title: 'رد المساعد', text: text, url: url });

                    return;
                }

                if (! await this.writeClipboard(text + '\n\n' + url)) {
                    throw new Error('share-failed');
                }

                this.$dispatch('toast', {
                    type: 'success',
                    title: 'تم النسخ',
                    message: 'تم نسخ الرد مع رابط المحادثة.',
                });
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                this.$dispatch('toast', {
                    type: 'error',
                    title: 'تعذر المشاركة',
                    message: 'المشاركة غير مدعومة على هذا المتصفح.',
                });
            }
        },
    }"
    @class([
        'flex w-full',
        'justify-end' => $isUser,
        'justify-start' => ! $isUser,
        'chat-user-message-row' => $isUser,
    ])
    :class="{ 'is-editing': editing, 'is-regenerating': regenerating, 'is-streaming-edit': streamingEdit }"
>
    @if ($isUser)
        <div class="chat-user-message-container flex flex-col items-end gap-1" dir="auto">
            <div
                x-show="! editing"
                class="chat-user-bubble rounded-2xl rounded-ee-md bg-muted px-3 py-1 text-start chat-user-accent"
            >
                <span data-user-message-content class="block whitespace-pre-wrap break-words text-sm leading-6 text-foreground">{{ $message->content }}</span>
                <time datetime="{{ $message->created_at->toIso8601String() }}" class="mt-0.5 block text-left text-[10px] leading-3 text-muted-foreground">
                    {{ \App\Support\ArabicDateTime::forMessage($message->created_at) }}
                </time>
            </div>

            <div x-show="editing" x-cloak wire:ignore class="mt-1 flex w-full flex-col gap-1">
                <textarea
                    x-ref="editTextarea"
                    @input="editContent = $event.target.value"
                    :disabled="saving"
                    maxlength="10000"
                    dir="auto"
                    class="w-full rounded-xl border border-input bg-surface px-3 py-2 text-sm leading-6 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring/20 disabled:opacity-60"
                    rows="2"
                >{{ $message->content }}</textarea>

                <p x-show="editError" x-cloak x-text="editError" class="text-xs text-destructive"></p>

                <div class="flex items-center justify-end gap-1.5">
                    <button
                        type="button"
                        @click="cancelEdit()"
                        :disabled="saving"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        إلغاء
                    </button>
                    <button
                        type="button"
                        @click="saveEdit()"
                        :disabled="saving || editContent.trim() === ''"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-medium ui-primary-btn disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <span x-text="saving ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span>
                    </button>
                </div>
            </div>

            @foreach (['content', 'message', 'conversation', 'edit'] as $errorKey)
                @if ($errors->has($errorKey))
                    <p x-show="editing && showServerError && !editError" class="w-full text-xs text-destructive">{{ $errors->first($errorKey) }}</p>
                @endif
            @endforeach

            <div x-show="! editing" class="mt-0.5 flex w-full flex-wrap items-center justify-end gap-2 text-[11px] text-muted-foreground">
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        data-copy
                        data-message-id="{{ $messageId }}"
                        @click="copyMessage()"
                        aria-label="نسخ الرسالة"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                        <span>نسخ</span>
                    </button>
                    <button
                        type="button"
                        @click="startEdit()"
                        aria-label="تعديل الرسالة"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                        <span>تعديل</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div class="flex w-full items-start gap-3" dir="auto">
            <div class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-primary" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z" />
                    <path d="M19 16.5 19.8 19l2.2.8-2.2.8L19 23l-.8-2.4L16 19.8l2.2-.8Z" />
                </svg>
            </div>

            <div class="min-w-0 flex-1">
                @if ($isFailed)
                    <div class="mb-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-destructive/10 px-2.5 py-0.5 text-[11px] font-medium text-destructive">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" />
                                <path d="M12 8v4M12 16h.01" />
                            </svg>
                            رد غير مكتمل
                        </span>
                    </div>
                @endif

                <div class="mt-1 text-sm leading-7 text-foreground" :aria-busy="regenerating || streamingEdit">
                    <div x-show="! regenerating && ! streamingEdit" class="ai-markdown break-words">{!! $html !!}</div>

                    <div x-show="regenerating && ! streamingEdit" data-assistant-regeneration class="flex flex-col gap-1">
                        <div
                            wire:stream="assistant-regeneration-{{ $messageId }}"
                            data-stream-target
                            class="whitespace-pre-wrap break-words"
                            dir="auto"
                        ></div>

                        <template x-if="regenerating">
                            <div class="typing-indicator flex items-center gap-1 text-muted-foreground" aria-hidden="true">
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                            </div>
                        </template>
                    </div>

                    <div x-show="streamingEdit" data-assistant-edit class="flex flex-col gap-1">
                        <div
                            wire:stream="assistant-edit-{{ $messageId }}"
                            data-stream-target
                            class="whitespace-pre-wrap break-words"
                            dir="auto"
                        ></div>

                        <template x-if="streamingEdit">
                            <div class="typing-indicator flex items-center gap-1 text-muted-foreground" aria-hidden="true">
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                            </div>
                        </template>
                    </div>

                    <time
                        x-show="! regenerating && ! streamingEdit"
                        datetime="{{ $message->created_at->toIso8601String() }}"
                        class="mt-0.5 block text-end text-[10px] leading-3 text-muted-foreground"
                    >
                        {{ \App\Support\ArabicDateTime::forMessage($message->created_at) }}
                    </time>
                </div>

                <div x-show="! regenerating && ! streamingEdit" class="mt-1 flex flex-wrap items-center justify-start gap-2 text-[11px] text-muted-foreground">
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            data-copy
                            data-message-id="{{ $messageId }}"
                            @click="copyMessage()"
                            aria-label="نسخ الرسالة"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></svg>
                            <span>نسخ</span>
                        </button>
                        @if (! $isFailed)
                            <button
                                type="button"
                                data-regenerate
                                data-message-id="{{ $messageId }}"
                                @click="regenerateMessage()"
                                wire:loading.attr="disabled"
                                wire:target="send,regenerateMessage"
                                aria-label="إعادة توليد الرد"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36" /><path d="M21 3v6h-6" /></svg>
                                <span x-text="regenerating ? 'جارٍ التوليد...' : 'إعادة التوليد'">إعادة التوليد</span>
                            </button>
                        @endif
                        <button
                            type="button"
                            data-share
                            data-message-id="{{ $messageId }}"
                            @click="shareMessage()"
                            aria-label="مشاركة الرد"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] text-zinc-500 dark:text-zinc-400 hover:bg-black/5 dark:hover:bg-white/10 hover:text-zinc-700 dark:hover:text-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current transition-colors"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="m8.59 13.51 6.83 3.98" /><path d="m15.41 6.51-6.83 3.98" /></svg>
                            <span>مشاركة</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
