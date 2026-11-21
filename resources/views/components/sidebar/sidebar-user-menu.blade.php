
@php($hasLogoutRoute = \Illuminate\Support\Facades\Route::has('logout'))

<div class="p-3 border-t border-border shrink-0" :class="sidebarOpen ? '' : 'lg:px-2 lg:grid lg:place-items-center'"

    @profile-updated.window="$el.querySelectorAll('[data-profile-name]').forEach((n) => { n.textContent = $event.detail.name || n.textContent; }); $el.querySelectorAll('[data-profile-initial]').forEach((n) => { n.textContent = $event.detail.initial || n.textContent; }); $el.querySelectorAll('[data-profile-email]').forEach((n) => { n.textContent = $event.detail.email || n.textContent; })">

    <div class="relative w-full " :class="sidebarOpen ? '' : 'lg:w-auto'" @click.outside="userMenu = false">

        <button type="button" @click="userMenu = !userMenu" :aria-expanded="userMenu ? 'true' : 'false'" aria-haspopup="menu" aria-controls="user-menu" aria-label="قائمة المستخدم"
            :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'"
            x-data="{ tip: false, tipTop: 0 }"
            @mouseenter="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
            @mouseleave="tip = false"
            @focusin="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
            @focusout="tip = false"
            class="w-full flex items-center gap-2.5 rounded-xl hover:bg-foreground/5  cursor-pointer
             focus-visible:bg-foreground/5  px-2 py-2 text-start transition-colors min-h-[48px]">
            <x-sidebar.collapsed-tip :label="auth()->user()->name ?? 'مستخدم'" />
            <span
                data-profile-initial
                class="w-9 h-9 rounded-full ui-primary-surface grid place-items-center text-sm font-semibold shrink-0" aria-hidden="true">{{ mb_substr(auth()->user()->name ?? 'م', 0, 1) }}</span>
            <span class="flex-1 min-w-0" :class="sidebarOpen ? '' : 'lg:hidden'"><span data-profile-name
                    class="block text-sm font-medium truncate">{{ auth()->user()->name ?? 'مستخدم' }}</span><span data-profile-email
                    class="block text-[11px] text-muted-foreground truncate">{{ auth()->user()->email ?? 'حسابي / إعدادات' }}</span></span>
            <svg class="w-4 h-4 shrink-0 text-muted-foreground transition-transform duration-200" :class="(userMenu ? 'rotate-180 ' : '') + (sidebarOpen ? '' : 'lg:hidden')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
        </button>

         <div x-show="userMenu" x-cloak
                x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-1"
                id="user-menu" role="menu" aria-label="قائمة المستخدم"
                class="absolute bottom-full mb-2 start-0 end-0 z-50 rounded-2xl bg-surface border border-border p-2"
                :class="sidebarOpen ? '' : 'lg:end-auto lg:w-64'">

                <div class="flex items-center gap-2.5 rounded-xl px-2 py-2">
                    <span data-profile-initial class="w-9 h-9 rounded-full ui-primary-surface grid place-items-center text-sm font-semibold shrink-0" aria-hidden="true">{{ mb_substr(auth()->user()->name ?? 'م', 0, 1) }}</span>
                    <span class="flex-1 min-w-0">
                        <span data-profile-name class="block text-sm font-semibold truncate text-foreground">{{ auth()->user()->name ?? 'مستخدم' }}</span>
                        <span data-profile-email class="block text-[11px] text-muted-foreground truncate">{{ auth()->user()->email ?? '' }}</span>
                    </span>
                </div>

                <div class="my-1.5 h-px bg-border" role="separator"></div>

                <a wire:navigate @click="userMenu = false"
                    href="{{ route('profile.show') }}"
                    role="menuitem"
                    class="flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-medium text-foreground
                           hover:bg-foreground/5 focus-visible:bg-foreground/5 transition-colors min-h-[40px]">
                    <svg class="w-4 h-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
                    </svg>
                    <span>الملف الشخصي</span>
                </a>

                <a wire:navigate @click="userMenu = false"
                    href="{{ route('personas.index') }}"
                    role="menuitem"
                    class="flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-medium text-foreground
                           hover:bg-foreground/5 focus-visible:bg-foreground/5 transition-colors min-h-[40px]">
                    <svg class="w-4 h-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z" /><path d="M8 9h8" /><path d="M8 12h5" />
                    </svg>
                    <span>الشخصيات</span>
                </a>

                <button type="button" @click="userMenu=false; window.dispatchEvent(new CustomEvent('open-settings-modal'))" role="menuitem"
                    class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-medium text-foreground
                           hover:bg-foreground/5 focus-visible:bg-foreground/5 transition-colors min-h-[40px] text-start">
                    <svg class="w-4 h-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
                    </svg>
                    <span>الإعدادات</span>
                </button>

                @auth
                    <div class="my-1.5 h-px bg-border" role="separator"></div>
                    @if($hasLogoutRoute)
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" role="menuitem" @click="userMenu = false"
                                class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-medium text-destructive
                                       hover:bg-destructive/10 focus-visible:bg-destructive/10 transition-colors min-h-[40px] text-start">
                                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" />
                                </svg>
                                <span>تسجيل الخروج</span>
                            </button>
                        </form>
                    @else
                        <button type="button" disabled aria-disabled="true" role="menuitem" title="مسار تسجيل الخروج غير مُعرّف بعد"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-xs font-medium text-destructive/60 cursor-not-allowed min-h-[40px] text-start">
                            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" />
                            </svg>
                            <span>تسجيل الخروج</span>
                        </button>
                    @endif
                @endauth
            </div>

    </div>

</div>
