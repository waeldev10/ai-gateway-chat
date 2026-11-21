
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
      class="fixed inset-y-0 right-0 z-40 flex flex-col w-[300px] lg:static lg:z-auto lg:translate-x-0
           sidebar-ambient text-foreground
           shrink-0 transition-all duration-200 border-e border-border min-h-0 overflow-visible">

    <x-sidebar.sidebar-header />

    <x-sidebar.sidebar-new-chat />

    <div class="flex-1 min-h-0 flex flex-col px-2 py-2 overflow-x-hidden">

        @auth

            @php($isSearchPage = request()->routeIs('conversations.search'))

            <a
                wire:navigate
                href="{{ route('conversations.search') }}"
                aria-label="بحث"
                @if ($isSearchPage) aria-current="page" @endif
                :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'"
                x-data="{ tip: false, tipTop: 0 }"
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
                @class([
                    'flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm shrink-0 transition-colors min-h-[44px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                    'bg-primary/10 font-medium text-primary' => $isSearchPage,
                    'text-muted-foreground hover:bg-primary/5 hover:text-foreground' => ! $isSearchPage,
                ])
            >
                <svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <span :class="sidebarOpen ? '' : 'lg:hidden'" class="truncate">بحث</span>
                <x-sidebar.collapsed-tip label="بحث" />
            </a>

            <div
                id="sidebar-conversations-scroll"
                :class="sidebarOpen ? '' : 'lg:hidden'"
                class="mt-1 flex-1 min-h-0 overflow-x-hidden overflow-y-auto ui-scroll-subtle"
            >
                @if (request()->header('X-Livewire-Navigate'))
                    <livewire:sidebar.sidebar-conversations :active-id="request()->route('conversation')?->id" />
                @else
                    <livewire:sidebar.sidebar-conversations lazy :active-id="request()->route('conversation')?->id" />
                @endif
            </div>

        @endauth

    </div>

    <x-sidebar.sidebar-user-menu />

</aside>
