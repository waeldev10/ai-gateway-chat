@props(['id'])

{{-- Sidebar rename modal (Alpine UI state + async submit to the existing PATCH
     route). Opened from the conversation actions menu via:
       window.dispatchEvent(new CustomEvent('open-rename-modal',
         { detail: { id: '<id>', action: '<form-url>', title: '...' } }))
     Opening/typing/cancel are purely browser-local. Save sends ONE async request
     (fetch) to the same form action/route, so the existing controller,
     authorization and validation logic is reused untouched — no duplicated
     business logic, no Livewire duplicate. `Accept: application/json` makes
     validation/authorization failures come back as JSON for the error toast.
     A plain form POST is NOT used because it would fully reload the page.
     `$wire` is NOT used because this modal is teleported to <body> (outside any
     Livewire root — required, since the translated/clipped <aside> would break
     a viewport-fixed modal), so no Livewire component is in scope here.
     On success the modal closes, both lists refresh through the existing
     `conversations-changed` Livewire event, and the unified `toast` event fires.
     Teleported to <body> so sidebar overflow can never clip it. --}}
<div x-data="{
        open: false, busy: false, actionUrl: '', currentTitle: '',
        async saveRename() {
            if (this.busy || ! this.actionUrl) return;
            this.busy = true;
            try {
                const res = await fetch(this.actionUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(this.$refs.renameForm),
                });
                if (! res.ok) {
                    let message = 'تعذر الحفظ. حاول مرة أخرى.';
                    try {
                        const data = await res.json();
                        message = data?.errors?.title?.[0] || data?.message || message;
                    } catch (e) {}
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر الحفظ', message: message } }));
                    return;
                }
                const savedTitle = this.currentTitle;
                this.open = false;
                if (window.Livewire) window.Livewire.dispatch('conversations-changed');
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', title: 'تمت إعادة التسمية', message: savedTitle } }));
            } catch (e) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر الحفظ', message: 'تعذر الاتصال بالخادم.' } }));
            } finally {
                this.busy = false;
            }
        },
    }"
    @open-rename-modal.window="if ($event.detail.id === @js($id)) { actionUrl = $event.detail.action || ''; currentTitle = $event.detail.title || ''; busy = false; open = true; $nextTick(() => $refs.renameInput?.focus()); }"
    @keydown.escape.window="if (open && !busy) { open = false; }" x-cloak>
    <template x-teleport="body">
        <div x-show="open" class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
            aria-modal="true" aria-labelledby="{{ $id }}-title">
            <div x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="if (!busy) { open = false; }" aria-hidden="true"></div>
            <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                class="relative bg-white dark:bg-zinc-900 rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-black/10 dark:border-white/10">
                <div class="flex items-start gap-3">
                    <span class="w-10 h-10 shrink-0 rounded-full bg-black/5 dark:bg-white/10 text-zinc-600 dark:text-zinc-200 grid place-items-center" aria-hidden="true">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" /><path d="m15 5 4 4" /></svg>
                    </span>
                    <h2 id="{{ $id }}-title" class="text-base font-semibold pt-2">إعادة تسمية المحادثة</h2>
                </div>
                <form x-ref="renameForm" :action="actionUrl" method="POST" @submit.prevent="saveRename()" class="mt-4">
                    @csrf @method('PATCH')
                    <input type="text" name="title" x-model="currentTitle" x-ref="renameInput" required maxlength="255"
                        aria-label="عنوان المحادثة"
                        class="w-full rounded-xl bg-black/5 dark:bg-white/10 px-3 py-2.5 text-sm focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-current">
                    <div class="flex gap-2 justify-end mt-5">
                        <button type="button" @click="if (!busy) { open = false; }" :disabled="busy"
                            class="px-5 py-2 rounded-full border border-black/10 dark:border-white/10 text-xs font-medium hover:bg-black/5 dark:hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors">إلغاء</button>
                        <button type="submit" :disabled="busy"
                            class="px-5 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                            <svg x-show="busy" x-cloak class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                            <span x-text="busy ? 'جارٍ الحفظ...' : 'حفظ'">حفظ</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
