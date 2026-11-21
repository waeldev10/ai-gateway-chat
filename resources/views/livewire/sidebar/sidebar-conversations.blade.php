<div>
    <div
        wire:loading
        wire:target="refreshList,conversations-changed"
        class="flex min-h-0 flex-1 flex-col px-1 py-1"
        aria-busy="true"
        aria-label="جارٍ تحديث المحادثات"
    >
        <x-conversation.list-skeleton :rows="8" />
    </div>

    <div
        wire:loading.remove
        wire:target="refreshList,conversations-changed"
        class="flex min-h-0 flex-1 flex-col"
    >
    
        @if ($groups['pinned']->isNotEmpty())

            <p class="px-3 pb-1 pt-2 text-[11px] font-medium text-muted-foreground">المثبتة</p>

            <ul role="list" class="space-y-0.5">
                @foreach ($groups['pinned'] as $conversation)
                    <li wire:key="sidebar-pinned-{{ $conversation->id }}">
                        <x-conversation.list-item
                            :conversation="$conversation"
                            context="sidebar"
                            :active="$activeId === (string) $conversation->id"
                        />
                    </li>
                @endforeach
            </ul>

        @endif

        @if ($groups['recent']->isNotEmpty())

            <p class="px-3 pb-1 pt-3 text-[11px] font-medium text-muted-foreground">الأخيرة</p>

            <ul role="list" class="space-y-0.5">
                @foreach ($groups['recent'] as $conversation)
                    <li wire:key="sidebar-recent-{{ $conversation->id }}">
                        <x-conversation.list-item
                            :conversation="$conversation"
                            context="sidebar"
                            :active="$activeId === (string) $conversation->id"
                        />
                    </li>
                @endforeach
            </ul>
            
        @endif

        @if ($groups['pinned']->isEmpty() && $groups['recent']->isEmpty())
            <p class="px-3 py-6 text-center text-xs text-muted-foreground">لا توجد محادثات</p>
        @endif

    </div>

    @if ($groups['hasMore'])
        <a
            wire:navigate
            href="{{ route('conversations.search') }}"
            class="mx-3 mb-2 mt-2 block rounded-xl px-3 py-2 text-center text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >عرض المزيد</a>
    @endif

    @livewire('conversation.conversation-actions', [
        'context' => 'sidebar',
        'activeId' => $activeId,
    ])

</div>
