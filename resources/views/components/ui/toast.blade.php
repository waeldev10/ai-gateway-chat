
@php
    $toastInitial = [];
    $pushToast = function ($item) use (&$toastInitial) {
        if (! is_array($item)) {
            return;
        }
        $message = trim((string) ($item['message'] ?? ''));
        if ($message === '') {
            return;
        }
        $type = (string) ($item['type'] ?? 'info');
        if (! in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            $type = 'info';
        }
        $toastInitial[] = [
            'type' => $type,
            'title' => ($item['title'] ?? null) !== null ? (string) $item['title'] : null,
            'message' => $message,
            'duration' => is_numeric($item['duration'] ?? null) ? (int) $item['duration'] : 4500,
        ];
    };
    foreach ((array) session('toasts', []) as $flashed) {
        $pushToast($flashed);
    }
    if (session()->has('toast')) {
        $pushToast(session('toast'));
    }
    if (session()->has('status')) {
        $pushToast(['type' => 'info', 'title' => null, 'message' => session('status')]);
    }
@endphp

<div x-data="{
        toasts: @js($toastInitial),
        push(detail) {
            const d = detail || {};
            const message = String(d.message || '').trim();
            if (!message) return;
            const allowed = ['success', 'error', 'warning', 'info'];
            const type = allowed.includes(d.type) ? d.type : 'info';
            const duration = Number(d.duration);
            const t = {
                key: Date.now().toString(36) + Math.random().toString(36).slice(2),
                type: type,
                title: d.title ? String(d.title) : null,
                message: message,
                duration: Number.isFinite(duration) ? Math.min(Math.max(duration, 2000), 12000) : 4500,
                leaving: false,
                timer: null,
            };
            this.toasts.push(t);
            if (this.toasts.length > 5) this.remove(this.toasts[0].key);
            this.schedule(t);
        },
        schedule(t) {
            if (t.timer) clearTimeout(t.timer);
            t.timer = setTimeout(() => this.dismiss(t.key), t.duration || 4500);
        },
        dismiss(key) {
            const t = this.toasts.find((item) => item.key === key);
            if (!t || t.leaving) return;
            t.leaving = true;
            setTimeout(() => this.remove(key), 180);
        },
        remove(key) {
            const i = this.toasts.findIndex((item) => item.key === key);
            if (i === -1) return;
            if (this.toasts[i].timer) clearTimeout(this.toasts[i].timer);
            this.toasts.splice(i, 1);
        },
    }" x-init="toasts.forEach((t) => schedule(t))"
    @toast.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-0 top-3 z-[80] flex flex-col items-center gap-2 px-4 sm:top-5"
    role="region" aria-label="التنبيهات" aria-live="polite">
    <template x-for="t in toasts" :key="t.key">
        <div x-show="!t.leaving"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0 scale-100" x-transition:leave-end="opacity-0 -translate-y-2 scale-95"
            :role="t.type === 'error' ? 'alert' : 'status'"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-2xl border border-border bg-surface p-3 text-foreground shadow-2xl">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full"
                :class="t.type === 'success' ? 'bg-success/15 text-success' : (t.type === 'error' ? 'bg-destructive/15 text-destructive' : (t.type === 'warning' ? 'bg-warning/15 text-warning' : 'bg-info/15 text-info'))"
                aria-hidden="true">
                <svg x-show="t.type === 'success'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                <svg x-show="t.type === 'error'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="m15 9-6 6" /><path d="m9 9 6 6" /></svg>
                <svg x-show="t.type === 'warning'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" /><path d="M12 9v4" /><path d="M12 17h.01" /></svg>
                <svg x-show="t.type === 'info'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><path d="M12 16v-4" /><path d="M12 8h.01" /></svg>
            </span>
            <span class="min-w-0 flex-1 pt-0.5">
                <span x-show="t.title" x-text="t.title" class="block truncate text-sm font-semibold text-foreground"></span>
                <span x-text="t.message" class="block text-xs leading-relaxed text-muted-foreground"></span>
            </span>
            <button type="button" @click="dismiss(t.key)" aria-label="إغلاق التنبيه"
                class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
            </button>
        </div>
    </template>
</div>
