@props(['name'])

<div class="flex w-full items-start gap-3" dir="auto">
    <div class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-primary" aria-hidden="true">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z" />
            <path d="M19 16.5 19.8 19l2.2.8-2.2.8L19 23l-.8-2.4L16 19.8l2.2-.8Z" />
        </svg>
    </div>

    <div class="min-w-0 flex-1 text-sm leading-7 text-foreground">
        <x-chat.streaming-text :name="$name" />
    </div>
</div>
