
<style>
    @media (min-width: 1024px) {
        aside[data-chat-sidebar][x-cloak] { display: flex !important; }
    }
</style>


<div x-show="drawer" x-cloak
    x-transition:enter="transition-opacity duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-30 bg-black/60 backdrop-blur-sm lg:hidden" @click="drawer = false; userMenu = false" aria-hidden="true">
</div>

<aside data-chat-sidebar id="chat-sidebar" x-cloak
    :class="{ 'translate-x-0': drawer, 'translate-x-full lg:translate-x-0': !drawer, 'lg:w-[280px]': sidebarOpen, 'lg:w-[72px]': !sidebarOpen }"
    @click="
        if (drawer && $event.target.closest('a[href]')) {
             drawer = false; userMenu = false; 
        }
    "
    class="fixed inset-y-0 right-0 z-40 flex flex-col w-[300px] lg:static lg:z-auto lg:translate-x-0 bg-[#fafafa] dark:bg-[#0a0a0a] text-zinc-800
     dark:text-[#e5e7eb] shrink-0 transition-all duration-200 border-e border-black/10 dark:border-white/10 min-h-0 overflow-visible">

    <x-sidebar.sidebar-header />

    <x-sidebar.sidebar-new-chat />

    <div class="flex-1 min-h-0 flex flex-col px-2 py-2 overflow-x-hidden overflow-y-auto ui-scroll-subtle">

        {{-- @auth --}}

            <a wire:navigate 
            {{-- href="{{ route('conversations.search') }}" --}}
             aria-label="بحث"
                :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'"
                x-data="
                 { tip: false, tipTop: 0 }"
                {{--@mouseenter="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
                @focusin="tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches; if (tip) { const r = $el.getBoundingClientRect(); tipTop = Math.round(r.top + r.height / 2); }"
                --}}
                @mouseenter="
                    tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches;

                    if (tip) {
                        const rect = $el.getBoundingClientRect();
                        tipTop = Math.round(rect.top + rect.height / 2);
                    }
                "
                @mouseleave="tip = false"
                @focusin="
                    tip = !sidebarOpen && window.matchMedia('(min-width: 1024px)').matches;

                    if (tip) {
                        const rect = $el.getBoundingClientRect();
                        tipTop = Math.round(rect.top + rect.height / 2);
                    }
                "
                @focusout="tip = false"
                class="flex items-center gap-2.5 rounded-xl hover:bg-black/5 dark:hover:bg-white/10 focus-visible:bg-black/5
                 dark:focus-visible:bg-white/10 px-3 py-2.5 text-sm text-zinc-600 dark:text-zinc-300 shrink-0 transition-colors min-h-[44px]">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" 
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                </svg>
                <span :class="sidebarOpen ? '' : 'lg:hidden'" class="truncate">بحث</span>
                <x-sidebar.collapsed-tip label="بحث" />
            </a>

         

        {{-- @endauth --}}
    </div>

    <x-sidebar.sidebar-user-menu />

</aside>
