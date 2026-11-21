@props(['name'])

<div data-streaming-text class="flex flex-col gap-1">
    <div
        wire:stream="{{ $name }}"
        data-stream-target
        class="whitespace-pre-wrap break-words"
        dir="auto"
    ></div>

    <div class="typing-indicator flex items-center gap-1 text-muted-foreground" aria-hidden="true">
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
        <span class="typing-dot"></span>
    </div>
</div>
