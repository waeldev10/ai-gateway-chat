{{-- Compact theme switcher used by the Auth layout and the app settings modal.
     It reuses the single localStorage-backed mechanism:
     initial value from `window.__currentTheme()`, writes through the single
     `window.__setTheme()` implementation in `resources/js/app.js`. The local
     `theme` variable is a display mirror only — it is never written anywhere
     except the shared preference, so no second theme state exists. --}}
<div x-data="{
        theme: 'system',
        setTheme(value) {
            this.theme = value;
            window.__setTheme && window.__setTheme(value);
            $dispatch('theme-changed', { theme: value });
        }
    }"
    x-init="theme = window.__currentTheme ? window.__currentTheme() : 'system'"
    @storage.window="if ($event.key === 'theme' && window.__currentTheme) theme = window.__currentTheme()"
    @theme-changed.window="theme = $event.detail.theme"
    class="grid grid-cols-3 gap-1 rounded-full bg-muted p-1 w-44" role="group" aria-label="اختيار المظهر">
    <button type="button" @click="setTheme('light')" :aria-pressed="theme === 'light' ? 'true' : 'false'"
        :class="theme === 'light' ? 'bg-primary text-primary-foreground shadow' : 'text-muted-foreground hover:bg-surface/60 hover:text-foreground'"
        class="flex items-center justify-center gap-1 rounded-full px-2 py-1.5 text-[11px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
        <span>فاتح</span>
    </button>
    <button type="button" @click="setTheme('dark')" :aria-pressed="theme === 'dark' ? 'true' : 'false'"
        :class="theme === 'dark' ? 'bg-primary text-primary-foreground shadow' : 'text-muted-foreground hover:bg-surface/60 hover:text-foreground'"
        class="flex items-center justify-center gap-1 rounded-full px-2 py-1.5 text-[11px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" /></svg>
        <span>داكن</span>
    </button>
    <button type="button" @click="setTheme('system')" :aria-pressed="theme === 'system' ? 'true' : 'false'"
        :class="theme === 'system' ? 'bg-primary text-primary-foreground shadow' : 'text-muted-foreground hover:bg-surface/60 hover:text-foreground'"
        class="flex items-center justify-center gap-1 rounded-full px-2 py-1.5 text-[11px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8m-4-4v4" /></svg>
        <span>تلقائي</span>
    </button>
</div>
