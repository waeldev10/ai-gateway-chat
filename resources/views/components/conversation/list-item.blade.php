@props([
    'conversation',
    'context' => 'sidebar',
    'active' => false,
    'showDate' => false,
    'selectable' => false,
])

@php
    $title = filled($conversation->title) ? (string) $conversation->title : 'محادثة جديدة';
    $pinned = $conversation->pinned_at !== null;
    $url = route('conversations.show', $conversation);
    $timestamp = $conversation->updated_at ?? $conversation->created_at;
    $rowInstance = $context.':'.$conversation->id;
@endphp

<div
    x-data="{
        actionsRevealed: false,
        pressing: false,
        ignoreNextClick: false,
        pressTimer: null,
        startX: 0,
        startY: 0,
        startLongPress(event) {
            this.ignoreNextClick = false;

            if (event.pointerType === 'mouse' || event.button > 0 || event.isPrimary === false) {
                return;
            }

            if (event.target.closest('button, input, [role=menu]')) {
                return;
            }

            this.cancelLongPress();

            this.pressing = true;
            this.startX = event.clientX;
            this.startY = event.clientY;

            this.pressTimer = window.setTimeout(() => {
                this.pressTimer = null;
                this.ignoreNextClick = true;
                this.revealActions();
            }, 500);
        },
        moveLongPress(event) {
            if (! this.pressing || this.pressTimer === null) {
                return;
            }

            if (Math.abs(event.clientX - this.startX) > 10 || Math.abs(event.clientY - this.startY) > 10) {
                this.cancelLongPress();
            }
        },
        cancelLongPress() {
            if (this.pressTimer !== null) {
                window.clearTimeout(this.pressTimer);
                this.pressTimer = null;
            }

            this.pressing = false;
        },
        finishLongPress() {
            this.cancelLongPress();
        },
        suppressNavigation(event) {
            if (! this.ignoreNextClick || event.detail === 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.ignoreNextClick = false;
        },
        revealActions() {
            window.dispatchEvent(new CustomEvent('conversation-actions-revealed', {
                detail: { instance: @js($rowInstance) },
            }));

            this.actionsRevealed = true;

            const selection = window.getSelection();

            if (selection) {
                selection.removeAllRanges();
            }
        },
        clearActions() {
            this.actionsRevealed = false;
        },
    }"
    @pointerdown="startLongPress($event)"
    @pointermove="moveLongPress($event)"
    @pointerup="finishLongPress()"
    @pointercancel="finishLongPress()"
    @pointerleave="finishLongPress()"
    @scroll.window.passive="finishLongPress()"
    @click.capture="suppressNavigation($event)"
    @contextmenu="if (pressing) { $event.preventDefault() }"
    @conversation-actions-revealed.window="if ($event.detail.instance !== @js($rowInstance)) { clearActions() }"
    @conversation-actions-menu-closed.window="if ($event.detail.instance === @js($rowInstance)) { clearActions() }"
    @pointerdown.window.capture="if (actionsRevealed && ! $el.contains($event.target) && ! $event.target.closest('[role=menu]')) { clearActions() }"
    :class="{ 'conversation-item-pressing': pressing }"
    class="conversation-item-row group relative flex items-center"
>
    @if ($selectable)

        <input
            type="checkbox"
            x-model="selected"
            value="{{ $conversation->id }}"
            data-conversation-select
            aria-label="تحديد {{ $title }}"
            class="ui-checkbox ms-2 h-4 w-4 shrink-0 rounded border-primary text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >

    @endif

    <a
        href="{{ $url }}"
        wire:navigate
        @if ($active) aria-current="page" @endif
        @class([
            'conversation-item-link flex min-w-0 flex-1 gap-2 rounded-xl px-2.5 pe-3 text-sm transition-colors md:pe-10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
            'items-center py-2 min-h-[40px]' => ! $showDate,
            'items-start py-2.5 min-h-[56px]' => $showDate,
            'bg-primary/10 font-medium text-primary' => $active,
            'text-muted-foreground hover:bg-primary/5 hover:text-foreground' => ! $active,
        ])
    >
        @if ($pinned)
            <svg
                @class([
                    'h-3.5 w-3.5 shrink-0 text-muted-foreground',
                    'mt-1' => $showDate,
                ])
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                role="img"
                aria-label="مثبتة"
            >
                <path d="M12 17v5" />
                <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z" />
            </svg>
        @endif

        <span class="min-w-0 flex-1">
            <span class="block truncate">{{ $title }}</span>

            @if ($showDate)
                <span class="mt-0.5 block text-[11px] font-normal text-muted-foreground">
                    {{ \App\Support\ArabicDateTime::forConversation($timestamp) }}
                </span>
            @endif
        </span>
    </a>

    <div
        class="conversation-item-actions absolute end-1.5 top-1/2 -translate-y-1/2"
        :class="{ 'conversation-item-actions-revealed': actionsRevealed }"
    >
        <x-conversation.actions-menu
            :id="$conversation->id"
            :title="$conversation->title"
            :pinned="$pinned"
            :url="$url"
            :context="$context"
        />
    </div>

</div>
