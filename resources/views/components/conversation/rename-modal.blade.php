@props([
    'context' => 'sidebar',
])

@php
    $modalContext = (string) $context;
@endphp

<div
    x-data="{
        open: false,
        busy: false,
        id: '',
        title: '',
        error: '',
        show(detail) {
            this.id = detail.id;
            this.title = detail.title || '';
            this.busy = false;
            this.error = '';
            this.open = true;

            this.$nextTick(() => {
                this.$refs.input?.focus();
                this.$refs.input?.select();
            });
        },
        close() {
            if (this.busy) {
                return;
            }

            this.open = false;
            this.error = '';
        },
        submit() {
            if (this.busy || this.id === '') {
                return;
            }

            this.error = '';
            this.busy = true;

            window.dispatchEvent(new CustomEvent('conversation-rename-requested', {
                detail: {
                    id: this.id,
                    title: this.title,
                    scope: @js($modalContext),
                },
            }));
        },
    }"
    @open-conversation-rename.window="if ($event.detail.scope === @js($modalContext)) { show($event.detail) }"
    @conversation-rename-succeeded.window="if ($event.detail.scope === @js($modalContext)) { open = false; busy = false; error = ''; }"
    @conversation-rename-failed.window="if ($event.detail.scope === @js($modalContext)) { busy = false; error = $event.detail.message || 'تعذر حفظ الاسم.'; $nextTick(() => $refs.input?.focus()); }"
    @keydown.escape.window="close()"
    x-cloak
>
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4"
        >
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                @click="close()"
                aria-hidden="true"
            ></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                role="dialog"
                aria-modal="true"
                aria-labelledby="conversation-rename-title-{{ $modalContext }}"
                class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-5"
            >
                <h2 id="conversation-rename-title-{{ $modalContext }}" class="text-base font-semibold text-foreground">إعادة تسمية المحادثة</h2>
                <p class="mt-1 text-sm text-muted-foreground">اختر اسمًا واضحًا يسهّل العثور على المحادثة لاحقًا.</p>

                <form class="mt-4" @submit.prevent="submit()">
                    <label for="conversation-rename-input-{{ $modalContext }}" class="mb-1.5 block text-sm font-medium text-foreground">اسم المحادثة</label>
                    <input
                        id="conversation-rename-input-{{ $modalContext }}"
                        type="text"
                        x-ref="input"
                        x-model="title"
                        @input="error = ''"
                        :disabled="busy"
                        maxlength="255"
                        required
                        autocomplete="off"
                        class="w-full rounded-xl border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :class="error ? 'border-destructive focus:border-destructive focus:ring-destructive/25' : 'border-border focus:border-ring focus:ring-ring/25'"
                    >
                    <p x-show="error" x-text="error" class="mt-1.5 text-xs text-destructive" role="alert"></p>

                    <div class="mt-5 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="close()"
                            :disabled="busy"
                            class="inline-flex min-h-9 items-center justify-center rounded-xl border border-border px-3.5 text-sm font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                        >إلغاء</button>

                        <button
                            type="submit"
                            :disabled="busy || title.trim() === ''"
                            class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-xl ui-primary-btn px-3.5 text-sm font-medium transition-opacity focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <svg x-show="busy" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                            <span x-text="busy ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
