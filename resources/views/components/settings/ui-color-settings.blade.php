
@php
    // Static editor fallback only: tokens are fixed, each read explicitly.
    // Every value is validated as strict #RRGGBB before reaching markup.
    $uiHex = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) === 1
        ? strtoupper(trim($v))
        : null;
    $initial = [
        'primary' => $uiHex(config('ui.colors.defaults.light.primary')) ?? '#4F46E5',
        'primary_text' => $uiHex(config('ui.colors.defaults.light.primary_text')) ?? '#FFFFFF',
        'accent' => $uiHex(config('ui.colors.defaults.light.accent')) ?? '#7C3AED',
        'link' => $uiHex(config('ui.colors.defaults.light.link')) ?? '#4338CA',
    ];
@endphp

<div x-data="{
        open: false,
        saved: @js($initial),
        draft: @js($initial),
        // Single source of truth: all color logic lives in window.__uiColors
        // (defined by the ui-colors head partial). Nothing here touches
        // localStorage or CSS directly; every value reaching CSS has passed
        // that helper's strict #RRGGBB validation. Fail closed if absent.
        api() { return (typeof window !== 'undefined' && window.__uiColors) || null; },
        clone(c) { try { return JSON.parse(JSON.stringify(c)); } catch (e) { return c; } },
        stored() { const a = this.api(); return a ? a.read() : {}; },
        hasStored(s) { s = s || this.stored(); return ['primary', 'primary_text', 'accent', 'link'].every((t) => !!s[t]); },
        defaults() { const a = this.api(); return this.clone(a && a.defaults ? a.defaults() : @js($initial)); },
        apply(c) { const a = this.api(); if (a) a.apply(c); },
        clearAll() { const a = this.api(); if (a) a.clear(); },
        syncFromStorage() {
            const s = this.stored();
            this.saved = this.hasStored(s) ? this.clone(s) : this.defaults();
            this.draft = this.clone(this.saved);
            return s;
        },
        preview() { this.apply(this.draft); },
        revert() {
            this.draft = this.clone(this.saved);
            const s = this.stored();
            if (this.hasStored(s)) { this.apply(s); } else { this.clearAll(); }
        },
        validPalette(c) {
            const a = this.api();
            if (!a || !c) return false;
            return ['primary', 'primary_text', 'accent', 'link'].every((t) => !!a.sanitize(c[t]));
        },
        save() {
            const a = this.api();
            if (!a) {
                $dispatch('toast', { type: 'error', title: 'تعذر الحفظ', message: 'تعذر حفظ الألوان في المتصفح.' });
                return;
            }
            if (!this.validPalette(this.draft)) {
                $dispatch('toast', { type: 'error', title: 'تعذر الحفظ', message: 'قيم الألوان غير صالحة.' });
                return;
            }
            // The helper re-validates, normalizes to uppercase, and stores
            // only a complete 4-token palette (null otherwise: save nothing).
            const savedPalette = a.save({
                primary: this.draft.primary,
                primary_text: this.draft.primary_text,
                accent: this.draft.accent,
                link: this.draft.link,
            });
            if (!savedPalette) {
                $dispatch('toast', { type: 'error', title: 'تعذر الحفظ', message: 'تعذر حفظ الألوان في المتصفح.' });
                return;
            }
            $dispatch('ui-colors-saved', { colors: this.clone(savedPalette) });
            $dispatch('close-settings-modal');
            $dispatch('toast', { type: 'success', title: 'الإعدادات', message: 'تم حفظ ألوان الواجهة في متصفحك.' });
        },
        resetColors() {
            this.clearAll();
            $dispatch('ui-colors-saved', { colors: {} });
            $dispatch('close-settings-modal');
            $dispatch('toast', { type: 'success', title: 'الإعدادات', message: 'تمت استعادة الألوان الافتراضية.' });
        },
    }"
    x-init="(() => { const s = syncFromStorage(); if (hasStored(s)) { apply(s); } })()"
    @open-settings-modal.window="syncFromStorage(); const s = stored(); if (hasStored(s)) { apply(s); } else { clearAll(); } open = true; $nextTick(() => $refs.firstColor?.focus());"
    @close-settings-modal.window="open = false;"
    @theme-changed.window="if (!hasStored() && JSON.stringify(draft) === JSON.stringify(saved)) { saved = defaults(); draft = clone(saved); clearAll(); }"
    @ui-colors-saved.window="if ($event.detail && $event.detail.colors && validPalette($event.detail.colors)) { saved = clone($event.detail.colors); draft = clone(saved); apply(saved); } else if ($event.detail && $event.detail.colors) { saved = defaults(); draft = clone(saved); clearAll(); }"
    @keydown.escape.window="if (open) { revert(); open = false; }"
    x-cloak>
    <div x-show="open" class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog"
        aria-modal="true" aria-labelledby="settings-title" aria-describedby="settings-desc">
        <div x-show="open" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="revert(); open = false;" aria-hidden="true"></div>
        <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
            class="relative bg-surface text-foreground rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-border max-h-[90vh] overflow-y-auto">
            <div class="flex items-start gap-3">
                <span class="w-10 h-10 shrink-0 rounded-full bg-muted text-muted-foreground grid place-items-center" aria-hidden="true">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" /></svg>
                </span>
                <span class="flex-1 min-w-0">
                    <h2 id="settings-title" class="text-base font-semibold">الإعدادات</h2>
                    <p id="settings-desc" class="text-sm text-muted-foreground mt-1 leading-relaxed">تخصيص ألوان الواجهة. التغيير يظهر فوراً، والحفظ يحفظه في متصفحك فقط.</p>
                </span>
                <button type="button" x-ref="closeBtn" @click="revert(); open = false;" aria-label="إغلاق الإعدادات"
                    class="w-8 h-8 shrink-0 grid place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
                </button>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/40 px-3 py-2.5">
                <span class="min-w-0">
                    <span class="block text-xs font-medium">المظهر</span>
                    <span class="block text-[11px] text-muted-foreground">فاتح أو داكن أو حسب النظام</span>
                </span>
                <x-theme.theme-toggle />
            </div>

            <div class="mt-3 space-y-3">
                <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-border shrink-0" :style="'background-color: ' + draft.primary" aria-hidden="true"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">اللون الرئيسي (الأزرار)</span>
                        <span class="block text-[11px] text-muted-foreground" dir="ltr" x-text="draft.primary"></span>
                    </span>
                    <input type="color" x-ref="firstColor" x-model="draft.primary" @input="preview()" aria-label="اللون الرئيسي"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-input bg-transparent p-1">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-border shrink-0 grid place-items-center text-xs font-semibold" :style="'background-color: ' + draft.primary_text + '; color: ' + draft.primary" aria-hidden="true">أ</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون نص الأزرار</span>
                        <span class="block text-[11px] text-muted-foreground" dir="ltr" x-text="draft.primary_text"></span>
                    </span>
                    <input type="color" x-model="draft.primary_text" @input="preview()" aria-label="لون نص الأزرار"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-input bg-transparent p-1">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-border shrink-0" :style="'background-color: ' + draft.accent" aria-hidden="true"></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون التمييز</span>
                        <span class="block text-[11px] text-muted-foreground" dir="ltr" x-text="draft.accent"></span>
                    </span>
                    <input type="color" x-model="draft.accent" @input="preview()" aria-label="لون التمييز"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-input bg-transparent p-1">
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5">
                    <span class="w-8 h-8 rounded-full border border-border shrink-0 grid place-items-center text-xs font-semibold underline" :style="'color: ' + draft.link" aria-hidden="true">رابط</span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-xs font-medium">لون الروابط</span>
                        <span class="block text-[11px] text-muted-foreground" dir="ltr" x-text="draft.link"></span>
                    </span>
                    <input type="color" x-model="draft.link" @input="preview()" aria-label="لون الروابط"
                        class="w-10 h-10 shrink-0 cursor-pointer rounded-lg border border-input bg-transparent p-1">
                </div>
                <p class="text-[11px] text-muted-foreground leading-relaxed">يعمل مع الوضع الفاتح والداكن. يُحفظ في متصفحك فقط ولا يغادر جهازك.</p>
            </div>

            <div class="flex gap-2 justify-between mt-5 flex-wrap">
                <button type="button" @click="resetColors()"
                    class="px-5 py-2 rounded-full border border-border text-xs font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-h-[36px] inline-flex items-center gap-1.5 transition-colors">
                    <span>استعادة الألوان الافتراضية</span>
                </button>
                <div class="flex gap-2">
                    <button type="button" @click="revert(); open = false;"
                        class="px-5 py-2 rounded-full border border-border text-xs font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-h-[36px] transition-colors">إلغاء</button>
                    <button type="button" @click="save()"
                        class="px-5 py-2 rounded-full ui-primary-btn text-xs font-medium inline-flex items-center gap-1.5 min-h-[36px] transition-colors">
                        <span>حفظ</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
