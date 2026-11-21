@props(['label'])

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
            class="-translate-y-1/2 rounded-xl border border-border bg-surface px-3 py-2 text-foreground">
            <span class="flex max-w-[260px] items-center gap-2">{{ $slot }}<span class="truncate text-xs font-medium text-foreground">{{ $label }}</span></span>
        </div>
    </div>
</template>
