<div class="flex flex-col gap-4" aria-busy="true" aria-label="جارٍ تحميل المحادثات">
    <span class="sr-only">جارٍ تحميل المحادثات...</span>

    <div class="relative flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1.5" aria-hidden="true">
        <svg class="h-4 w-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>

        <span class="h-4 min-w-0 flex-1 animate-pulse rounded bg-muted"></span>

        <span class="h-4 w-4 shrink-0 animate-pulse rounded-full bg-muted"></span>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 px-1" aria-hidden="true">
        <div class="flex items-center gap-3">
            <span class="h-4 w-4 animate-pulse rounded border border-border bg-muted"></span>
            <span class="h-2.5 w-20 animate-pulse rounded bg-muted"></span>
        </div>

        <span class="h-2.5 w-24 animate-pulse rounded bg-muted"></span>
    </div>

    <x-conversation.list-skeleton :rows="6" :selectable="true" :show-date="true" />
</div>
