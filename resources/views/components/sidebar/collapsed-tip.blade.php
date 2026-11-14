@props(['label'])

{{-- Floating hover tooltip for the collapsed (72px) desktop sidebar.
     Must be placed inside an element carrying local Alpine hover state:
     `x-data="{ tip: false, tipTop: 0 }"` with mouse/focus handlers that set
     `tip`/`tipTop` from the hovered element's bounding rect.
     Visible only while the desktop sidebar is collapsed (`!sidebarOpen`) and
     never on mobile (`hidden lg:block` wrapper + matchMedia gate in handlers).
     Teleported to <body> so sidebar overflow/scroll contexts can never clip it.
     `pointer-events-none` so it can never block the underlying navigation. --}}
<template x-teleport="body">
    <div class="pointer-events-none fixed right-[84px] z-50 hidden lg:block" :style="{ top: tipTop + 'px' }">
        <div x-show="tip && !sidebarOpen" x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-x-2 scale-95"
            x-transition:enter-end="opacity-100 translate-x-0 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-x-0 scale-100"
            x-transition:leave-end="opacity-0 translate-x-2 scale-95"
            role="tooltip"
            class="-translate-y-1/2 rounded-xl border border-black/10 bg-white px-3 py-2 shadow-xl dark:border-white/10 dark:bg-[#1e293b]">
            <span class="flex max-w-[260px] items-center gap-2">{{ $slot }}<span class="truncate text-xs font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</span></span>
        </div>
    </div>
</template>
