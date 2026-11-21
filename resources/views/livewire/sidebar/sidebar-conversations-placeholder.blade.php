<div
    class="flex min-h-0 flex-1 flex-col px-1 py-1"
    aria-busy="true"
    aria-label="جارٍ تحميل المحادثات"
>
    <span class="sr-only">جارٍ تحميل المحادثات...</span>

    <div class="px-3 pb-1 pt-2" aria-hidden="true">
        <div class="h-2.5 w-16 animate-pulse rounded bg-muted"></div>
    </div>

    <x-conversation.list-skeleton :rows="8" />

    <div class="mx-3 mb-2 mt-2 rounded-xl px-3 py-2" aria-hidden="true">
        <div class="mx-auto h-3 w-20 animate-pulse rounded bg-muted"></div>
    </div>
</div>
