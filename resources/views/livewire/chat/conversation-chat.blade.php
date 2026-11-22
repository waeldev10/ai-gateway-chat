<div
    class="relative isolate flex min-h-0 flex-1 flex-col"
    data-conversation-chat
    data-conversation-id="{{ $conversationId ?? '' }}"
    data-chat-active="{{ $hasMessages ? 'true' : 'false' }}"
    x-data="{
        stick: true,
        scrolling: false,
        sending: false,
        generationActive: false,
        isOffline: ! navigator.onLine,
        providerUnavailable: false,
        loadingOlderMessages: false,
        showScrollToBottom: false,
        hasMessages: {{ $hasMessages ? 'true' : 'false' }},
        initialSendPending: {{ $shouldSendInitialMessage ? 'true' : 'false' }},
        pendingText: @js($initialSendContent ?? ''),
        get isActive() {
            return this.hasMessages;
        },
        scrollToBottom() {
            const scroll = this.$refs.scroll;

            if (scroll) {
                scroll.scrollTop = scroll.scrollHeight;
                this.stick = true;
                this.showScrollToBottom = false;
            }
        },
        initChatScroll() {
            const scroll = this.$refs.scroll;
            const content = this.$refs.content;

            if (! scroll) {
                return;
            }

            if (content && content.querySelector('[data-message]')) {
                this.hasMessages = true;
            }

            scroll.addEventListener('scroll', () => {
                if (this.scrolling) return;
                this.scrolling = true;
                requestAnimationFrame(() => {
                    this.stick = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 96;
                    this.showScrollToBottom = this.hasMessages && ! this.stick;
                    this.scrolling = false;
                });
            }, { passive: true });

            if (content) {
                new MutationObserver(() => {
                    if (content.querySelector('[data-message]')) {
                        this.hasMessages = true;
                    }

                    if (this.stick) {
                        this.scrollToBottom();
                    } else {
                        this.showScrollToBottom = this.hasMessages;
                    }
                }).observe(content, {
                    childList: true,
                    characterData: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class'],
                });
            }

            this.scrollToBottom();
        },
        resizeComposer() {
            const el = this.$refs.composer;

            if (! el) return;

            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
            el.style.overflowY = el.scrollHeight > 160 ? 'auto' : 'hidden';
        },
        resetComposerHeight() {
            const el = this.$refs.composer;

            if (! el) return;

            el.style.height = '';
            el.style.overflowY = 'hidden';
        },
        async loadOlderMessages() {
            const scroll = this.$refs.scroll;

            if (this.loadingOlderMessages || ! scroll) {
                return;
            }

            const previousHeight = scroll.scrollHeight;
            const previousTop = scroll.scrollTop;
            this.loadingOlderMessages = true;

            try {
                await this.$wire.loadOlderMessages();

                this.$nextTick(() => {
                    scroll.scrollTop = previousTop + scroll.scrollHeight - previousHeight;
                });
            } finally {
                this.$nextTick(() => {
                    this.loadingOlderMessages = false;
                });
            }
        },
        async startInitialMessage() {
            if (! this.initialSendPending || this.sending || this.isOffline) {
                return;
            }

            this.initialSendPending = false;
            this.sending = true;

            try {
                await this.$wire.sendInitialMessage();
            } catch (error) {
                if (! navigator.onLine) {
                    this.isOffline = true;
                    return;
                }

                window.dispatchEvent(new CustomEvent('toast', {
                    detail: {
                        type: 'error',
                        title: 'تعذر الإرسال',
                        message: 'حاول مرة أخرى.',
                    },
                }));
            } finally {
                this.sending = false;

                if (! this.$refs.content?.querySelector('[data-message]')) {
                    this.hasMessages = false;
                    this.pendingText = '';
                }
            }
        },
        handleConversationUpdated() {
            this.pendingText = '';

            if (this.$refs.content?.querySelector('[data-message]')) {
                this.hasMessages = true;
            }

            this.$nextTick(() => {
                if (this.stick) {
                    this.scrollToBottom();
                }

                this.resetComposerHeight();
                this.$refs.composer?.focus();
            });
        },
        async submitChat() {
            if (this.isOffline || this.sending || this.generationActive) {
                return;
            }

            const composer = this.$refs.composer;
            const text = (composer?.value || '').trim();

            if (text === '') {
                return;
            }

            this.pendingText = text;
            const hadMessages = this.hasMessages;
            const previousConversationId = this.$wire.conversationId;
            this.hasMessages = true;
            this.sending = true;

            if (composer) {
                composer.value = '';
            }

            this.$nextTick(() => {
                this.resetComposerHeight();
                this.scrollToBottom();
            });

            try {
                await this.$wire.send();
            } catch (error) {
                if (! navigator.onLine) {
                    this.isOffline = true;
                    return;
                }

                window.dispatchEvent(new CustomEvent('toast', {
                    detail: {
                        type: 'error',
                        title: 'تعذر الإرسال',
                        message: 'حاول مرة أخرى.',
                    },
                }));
            } finally {
                if (! hadMessages) {
                    const hasPersistedMessages = Boolean(this.$refs.content?.querySelector('[data-message]'));
                    const createdConversation = previousConversationId === null && this.$wire.conversationId !== null;

                    this.hasMessages = hasPersistedMessages || createdConversation;
                }

                this.sending = false;

                this.$nextTick(() => {
                    if (this.$refs.composer
                        && this.$refs.composer.value === ''
                        && (this.$wire.draft || '').trim() !== '') {
                        this.$refs.composer.value = this.$wire.draft;
                        this.resizeComposer();
                    }
                });
            }
        },
    }"
    x-init="$nextTick(() => { initChatScroll(); if (initialSendPending) startInitialMessage(); })"
    @offline.window="isOffline = true; providerUnavailable = false"
    @online.window="isOffline = false; providerUnavailable = false; if (initialSendPending) $nextTick(() => startInitialMessage())"
    @ai-provider-connection-failed.window="providerUnavailable = true"
    @ai-provider-connection-restored.window="providerUnavailable = false"
    @conversation-chat-updated.window="handleConversationUpdated()"
    @generation-started.window="generationActive = true"
    @generation-ended.window="generationActive = false"
    :data-chat-active="isActive ? 'true' : 'false'"
    :class="{
        'chat-sidebar-expanded': sidebarOpen,
        'chat-sidebar-collapsed': ! sidebarOpen,
    }"
>
    <div
        class="min-h-0 flex-1 overflow-y-auto"
        data-chat-scroll
        x-ref="scroll"
    >
        <div
            x-ref="content"
            class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6"
            :class="{ 'chat-messages-with-composer': isActive }"
        >
            <div class="space-y-4" role="log" aria-live="polite" aria-relevant="additions">
                @if ($hasOlderMessages)
                    <div
                        wire:key="older-messages-{{ $olderMessagesId }}"
                        data-older-messages-sentinel
                        x-intersect="loadOlderMessages()"
                        class="flex min-h-8 justify-center py-2 text-xs text-muted-foreground"
                        aria-live="polite"
                    >
                        <span x-show="loadingOlderMessages" x-cloak>جارٍ تحميل الرسائل السابقة...</span>
                    </div>
                @endif

                @foreach ($messages as $message)
                    <x-chat.message
                        :message="$message"
                        :next-id="$loop->last ? null : (string) $messages[$loop->index + 1]->id"
                    />
                @endforeach

                <!-- Optimistic user message shown immediately on send. -->
                <div
                    data-optimistic-user
                    class="hidden justify-end"
                    dir="ltr"
                    wire:loading.class.remove="hidden"
                    wire:loading.flex
                    wire:target="send,sendInitialMessage"
                    x-show="pendingText !== ''"
                >
                    <div class="chat-user-message-container flex flex-col items-end gap-1" dir="auto">
                        <div class="chat-user-bubble rounded-2xl rounded-ee-md bg-muted px-3 py-1 text-start chat-user-accent">
                            <span class="block whitespace-pre-wrap break-words text-sm leading-6 text-foreground" x-text="pendingText"></span>
                        </div>
                    </div>
                </div>

                <!-- Streamed assistant draft for send. Same structure as the final message. -->
                <div
                    wire:key="assistant-draft"
                    data-assistant-draft
                    class="hidden w-full"
                    wire:loading.class.remove="hidden"
                    wire:loading.flex
                    wire:target="send,sendInitialMessage"
                    aria-live="polite"
                    aria-busy="true"
                >
                    <x-chat.streaming-message name="assistant-draft" />
                </div>

                <!-- Streamed assistant draft for editing a user message with no existing assistant to replace. -->
                <div
                    wire:key="assistant-edit-new"
                    data-assistant-edit-new
                    class="hidden w-full"
                    x-data="{ editing: false }"
                    x-show="editing"
                    x-cloak
                    @edit-stream-started.window="editing = $event.detail.target === 'assistant-edit-new'"
                    @edit-stream-ended.window="editing = false"
                >
                    <x-chat.streaming-message name="assistant-edit-new" />
                </div>
            </div>

        </div>
    </div>

    <form
        data-composer-form
        class="mx-auto w-full max-w-3xl shrink-0"
        @submit.prevent="submitChat()"
        :class="{
            'chat-composer-sidebar-expanded': sidebarOpen,
            'chat-composer-sidebar-collapsed': ! sidebarOpen,
        }"
    >
        <button
            type="button"
            data-scroll-to-bottom
            x-show="showScrollToBottom"
            x-cloak
            @click="scrollToBottom()"
            aria-label="الانتقال إلى أحدث الرسائل"
            title="الانتقال إلى أحدث الرسائل"
            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border bg-surface text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 5v14m0 0 6-6m-6 6-6-6" />
            </svg>
        </button>

        <p data-empty-prompt class="mb-5 text-center text-lg font-medium text-foreground/80">كيف يمكنني مساعدتك؟</p>
        {{-- يرصد التنبيه أحداث اتصال المتصفح ونتائج عمليات المحادثة دون إرسال فحوص دورية.
             يظهر داخل صفحة المحادثة فقط لإبقاء بقية التطبيق قابلة للاستخدام. --}}
        <div
            x-show="isOffline || providerUnavailable"
            x-cloak
            data-chat-offline-notice
            role="status"
            aria-live="polite"
            class="mb-4 flex items-center gap-3 rounded-2xl border border-destructive/20 bg-destructive/5 px-4 py-3 text-foreground"
        >
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-destructive/15 bg-destructive/10 text-destructive" aria-hidden="true">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 8.8a15 15 0 0 1 20 0" />
                    <path d="M5 12a10 10 0 0 1 6.8-2.6" />
                    <path d="M8.5 15.2a5 5 0 0 1 2.3-1.2" />
                    <path d="M2 2l20 20" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span
                    x-text="providerUnavailable && ! isOffline ? 'تعذر الاتصال بمزود الذكاء الاصطناعي' : 'لا يتوفر اتصال بالإنترنت'"
                    class="block text-sm font-semibold text-foreground"
                ></span>
                <span
                    x-text="providerUnavailable && ! isOffline
                        ? 'تم حفظ رسالتك. انتظر عودة الاتصال قبل إرسال رسالة أخرى.'
                        : 'لن تُرسل الرسالة الآن. أعد الاتصال لإرسالها.'"
                    class="mt-0.5 block text-xs leading-5 text-muted-foreground"
                ></span>
            </span>
        </div>
        <div class="chat-composer-surface flex items-end gap-1.5 rounded-[1.75rem] border border-input/80 bg-surface p-1.5 transition focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/15">
            <textarea
                x-ref="composer"
                wire:model="draft"
                rows="1"
                maxlength="10000"
                dir="auto"
                placeholder="اكتب رسالتك..."
                aria-label="اكتب رسالتك"
                aria-describedby="{{ $errors->has('draft') ? 'draft-error' : '' }}"
                class="block max-h-40 min-h-11 w-full resize-none border-0 bg-transparent px-3 py-2.5 text-sm leading-6 text-foreground placeholder:text-muted-foreground focus:outline-none disabled:opacity-60"
                :disabled="sending || generationActive"
                wire:loading.attr="disabled"
                wire:target="send,sendInitialMessage"
                @input="resizeComposer()"
                @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); submitChat(); }"
            ></textarea>

            <button
                type="submit"
                aria-label="إرسال الرسالة"
                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-2xl ui-primary-btn px-3.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60 sm:px-4"
                :disabled="($wire.draft || '').trim() === '' || isOffline || sending || generationActive"
                wire:loading.attr="disabled"
                wire:target="send,sendInitialMessage"
            >
                <span class="sr-only">إرسال</span>
                <svg wire:loading.remove wire:target="send,sendInitialMessage" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m22 2-7 20-4-9-9-4Z" />
                    <path d="M22 2 11 13" />
                </svg>
                <svg wire:loading wire:target="send,sendInitialMessage" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-6.22-8.56" />
                </svg>
            </button>
        </div>

        @error('draft')
            <p id="draft-error" class="mt-1.5 px-1 text-xs text-destructive">{{ $message }}</p>
        @enderror
    </form>
</div>
