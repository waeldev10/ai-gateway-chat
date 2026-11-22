@props(['hasDraftError' => false, 'draftError' => null])

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
    {{-- يرصد التنبيه أحداث اتصال المتصفح ونتائج عمليات المحادثة دون إرسال فحوص دورية
         يظهر داخل صفحة المحادثة فقط لإبقاء بقية التطبيق قابلة للاستخدام --}}
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
            aria-describedby="{{ $hasDraftError ? 'draft-error' : '' }}"
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
            class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-3xl ui-primary-btn px-4 text-sm font-medium transition
             focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60 sm:px-5"
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

    @if ($hasDraftError)
        <p id="draft-error" class="mt-1.5 px-1 text-xs text-destructive">{{ $draftError }}</p>
    @endif
</form>
