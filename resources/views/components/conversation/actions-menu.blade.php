@props([
    'id',
    'title' => '',
    'pinned' => false,
    'url',
    'context' => 'sidebar',
    'reveal' => true,
])

@php
    $conversationId = (string) $id;
    $conversationTitle = (string) $title;
    $shareUrl = (string) $url;
    $menuScope = (string) $context;
    $menuInstance = $menuScope.':'.$conversationId;
@endphp

<div
    x-data="{
        open: false,
        menuTop: 0,
        menuLeft: 0,
        copyState: false,
        shareOpen: false,
        shareBusy: false,
        shareUrl: @js($shareUrl),
        place(button) {
            const rect = button.getBoundingClientRect();
            const width = 192;
            const margin = 8;

            this.menuLeft = Math.min(Math.max(margin, rect.right - width), window.innerWidth - width - margin);
            this.menuTop = rect.bottom + 6;

            this.$nextTick(() => {
                const menu = this.$refs.menu;

                if (! menu) {
                    return;
                }

                const height = menu.offsetHeight || 220;

                if (this.menuTop + height > window.innerHeight - margin) {
                    this.menuTop = Math.max(margin, rect.top - height - 6);
                }
            });
        },
        toggle(button) {
            window.dispatchEvent(new CustomEvent('conversation-menu-opened', { detail: { instance: @js($menuInstance) } }));

            if (this.open) {
                this.closeMenu();

                return;
            }

            this.open = true;
            this.place(button);
        },
        closeMenu() {
            if (! this.open) {
                return;
            }

            this.open = false;
            window.dispatchEvent(new CustomEvent('conversation-actions-menu-closed', {
                detail: { instance: @js($menuInstance) },
            }));
        },
        flash(state) {
            this.copyState = state;

            setTimeout(() => {
                if (this.copyState === state) {
                    this.copyState = false;
                }
            }, 1600);
        },
        notify(type, title, message) {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { type: type, title: title, message: message },
            }));
        },
        async copyText(text) {
            if (! text) {
                return false;
            }

            try {
                if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);

                    return true;
                }
            } catch (e) {
                // Fall through to the legacy selection-based fallback.
            }

            try {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.top = '0';
                area.style.left = '-9999px';
                area.style.opacity = '0';
                document.body.appendChild(area);

                const selection = document.getSelection();
                const previous = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

                area.select();
                area.setSelectionRange(0, area.value.length);

                const copied = document.execCommand && document.execCommand('copy');

                area.remove();

                if (previous && selection) {
                    selection.removeAllRanges();
                    selection.addRange(previous);
                }

                return Boolean(copied);
            } catch (e) {
                return false;
            }
        },
        async share() {
            this.closeMenu();

            const copied = await this.copyText(this.shareUrl);

            if (copied) {
                this.flash('copied');
                this.notify('success', 'تم نسخ رابط المشاركة', this.shareUrl);

                return;
            }

            this.flash('failed');
            this.shareOpen = true;
            this.notify('error', 'تعذر النسخ التلقائي', 'يمكنك نسخ الرابط يدوياً من النافذة.');

            this.$nextTick(() => {
                this.$refs.shareInput?.focus();
                this.$refs.shareInput?.select();
            });
        },
        async retryShare() {
            if (this.shareBusy) {
                return;
            }

            this.shareBusy = true;

            const copied = await this.copyText(this.shareUrl);

            this.shareBusy = false;

            if (copied) {
                this.flash('copied');
                this.shareOpen = false;
                this.notify('success', 'تم نسخ رابط المشاركة', this.shareUrl);

                return;
            }

            this.flash('failed');
            this.notify('error', 'تعذر النسخ', 'حدّد الرابط وانسخه يدوياً.');
            this.$nextTick(() => {
                this.$refs.shareInput?.focus();
                this.$refs.shareInput?.select();
            });
        },
        closeShare() {
            this.shareOpen = false;
        },
    }"
    @conversation-menu-opened.window="if ($event.detail.instance !== @js($menuInstance)) { closeMenu() }"
    @conversation-actions-revealed.window="closeMenu()"
    @keydown.escape.window="if (shareOpen) { closeShare() } else { closeMenu() }"
    @scroll.window.capture="closeMenu()"
    @resize.window="closeMenu()"
    data-share-url="{{ $shareUrl }}"
    class="relative shrink-0"
>
    <button
        type="button"
        @click.stop="toggle($el)"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="menu"
        aria-label="خيارات {{ $conversationTitle !== '' ? $conversationTitle : 'المحادثة' }}"
        class="grid h-8 w-8 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:bg-muted focus-visible:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring @if ($reveal) conversation-actions-reveal @endif"
        @if ($reveal)
            :class="{ 'conversation-actions-revealed': actionsRevealed }"
        @endif
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="12" r="1.8" />
            <circle cx="12" cy="12" r="1.8" />
            <circle cx="19" cy="12" r="1.8" />
        </svg>
    </button>

    <span
        x-show="copyState === 'copied'"
        x-cloak
        class="absolute end-0 top-full mt-1 whitespace-nowrap rounded-full bg-foreground px-2 py-0.5 text-[11px] text-background"
    >تم النسخ</span>

    <span
        x-show="copyState === 'failed'"
        x-cloak
        class="absolute end-0 top-full mt-1 whitespace-nowrap rounded-full bg-destructive px-2 py-0.5 text-[11px] text-destructive-foreground"
    >تعذر النسخ</span>

    <template x-teleport="body">
        <div
            x-ref="menu"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.outside="closeMenu()"
            :style="{ top: menuTop + 'px', left: menuLeft + 'px' }"
            role="menu"
            aria-label="إجراءات المحادثة"
            class="fixed z-50 w-48 origin-top rounded-xl border border-border bg-surface p-1.5"
        >
            <button
                type="button"
                role="menuitem"
                @click="closeMenu(); window.dispatchEvent(new CustomEvent('open-conversation-rename', { detail: { id: @js($conversationId), title: @js($conversationTitle), scope: @js($menuScope) } }))"
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm text-foreground transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 20h9" />
                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                </svg>
                <span>إعادة تسمية</span>
            </button>

            <button
                type="button"
                role="menuitem"
                @click="closeMenu(); window.dispatchEvent(new CustomEvent('conversation-pin-requested', { detail: { id: @js($conversationId), scope: @js($menuScope) } }))"
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm text-foreground transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            >
                @if ($pinned)
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 17v5" />
                        <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
                        <path d="m2 2 20 20" />
                    </svg>
                    <span>إلغاء التثبيت</span>
                @else
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 17v5" />
                        <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
                    </svg>
                    <span>تثبيت</span>
                @endif
            </button>

            <button
                type="button"
                role="menuitem"
                @click="share()"
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm text-foreground transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" />
                    <polyline points="16 6 12 2 8 6" />
                    <line x1="12" y1="2" x2="12" y2="15" />
                </svg>
                <span>مشاركة</span>
            </button>

            <button
                type="button"
                role="menuitem"
                @click="closeMenu(); window.dispatchEvent(new CustomEvent('conversation-delete-requested', { detail: { id: @js($conversationId), title: @js($conversationTitle), url: @js($shareUrl), scope: @js($menuScope) } }))"
                class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-sm text-destructive transition-colors hover:bg-destructive/10 focus-visible:bg-destructive/10 focus-visible:outline-none"
            >
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 6h18" />
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    <path d="M10 11v6" />
                    <path d="M14 11v6" />
                </svg>
                <span>حذف المحادثة</span>
            </button>
        </div>
    </template>

    <template x-teleport="body">
        <div
            x-show="shareOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[70] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="conversation-share-title-{{ $menuScope }}-{{ $conversationId }}"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeShare()" aria-hidden="true"></div>

            <div
                x-show="shareOpen"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-md rounded-2xl border border-border bg-surface p-5"
            >
                <h2 id="conversation-share-title-{{ $menuScope }}-{{ $conversationId }}" class="text-base font-semibold text-foreground">مشاركة المحادثة</h2>
                <p class="mt-1 text-sm text-muted-foreground">تعذر النسخ التلقائي. حدّد الرابط التالي وانسخه يدوياً.</p>

                <input
                    type="text"
                    x-ref="shareInput"
                    :value="shareUrl"
                    readonly
                    @focus="$el.select()"
                    @click="$el.select()"
                    aria-label="رابط المحادثة"
                    class="mt-4 w-full rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring/25"
                >

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        @click="closeShare()"
                        class="inline-flex min-h-9 items-center justify-center rounded-xl border border-border px-3.5 text-sm font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >إغلاق</button>

                    <button
                        type="button"
                        @click="retryShare()"
                        :disabled="shareBusy"
                        class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-xl ui-primary-btn px-3.5 text-sm font-medium transition-opacity focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <svg x-show="shareBusy" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        <span x-text="shareBusy ? 'جارٍ النسخ...' : 'إعادة المحاولة'">إعادة المحاولة</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
