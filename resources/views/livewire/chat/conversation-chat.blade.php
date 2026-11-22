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
        handleSendFailure() {
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
        },
        async startInitialMessage() {
            if (! this.initialSendPending || this.sending || this.isOffline) {
                return;
            }

            this.initialSendPending = false;
            this.sending = true;

            try {
                await this.$wire.sendInitialMessage();
            } catch {
                this.handleSendFailure();
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
            } catch {
                this.handleSendFailure();
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

    <x-chat.composer
        :has-draft-error="$errors->has('draft')"
        :draft-error="$errors->first('draft')"
    />
</div>
