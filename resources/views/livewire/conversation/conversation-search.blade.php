<div class="flex flex-col gap-4">

    <form
        wire:submit="searchAction"
        role="search"
        class="relative flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1.5 transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/20"
    >

        <svg class="h-4 w-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>

        <input
            type="search"
            x-ref="search"
            wire:model.live.debounce.500ms="search"
            @input="clearSelection()"
            maxlength="255"
            placeholder="ابحث في المحادثات..."
            aria-label="بحث في المحادثات"
            autocomplete="off"
            class="min-w-0 flex-1 bg-transparent px-1 py-1.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
        >

        @if ($search !== '')
            <button
                type="button"
                wire:click="clear"
                @click="clearSelection(); $refs.search?.focus()"
                aria-label="مسح البحث"
                class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6 6 18" />
                    <path d="m6 6 12 12" />
                </svg>
            </button>
        @endif

    </form>

    <div class="flex flex-wrap items-center justify-between gap-3 px-1 text-xs text-muted-foreground">
        
        <div class="flex items-center gap-3">
            @if ($results->total() > 0)
                <label class="inline-flex cursor-pointer items-center gap-1.5">
                    <input
                        type="checkbox"
                        :checked="allChecked"
                        x-effect="$el.indeterminate = someChecked"
                        @change="toggleAll($event.target.checked)"
                        aria-label="تحديد كل المحادثات المعروضة"
                        class="ui-checkbox h-4 w-4 rounded border-primary text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                    <span>تحديد الكل</span>
                </label>
            @endif

            <span>{{ $search !== '' ? 'نتائج البحث' : 'كل المحادثات' }}</span>
        </div>

        <span>{{ $search !== '' ? 'عدد النتائج' : 'عدد المحادثات' }}: {{ $results->total() }}</span>
    </div>

    <div
        x-cloak
        class="flex-wrap items-center justify-between gap-2 rounded-2xl border border-destructive/20 bg-destructive/5 px-3 py-2"
        :class="{ 'hidden': selected.length === 0, 'flex': selected.length > 0 }"
    >
        <span class="text-xs font-medium text-foreground">
            <span x-text="selected.length">0</span> محددة
        </span>

        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="clearSelection()"
                class="inline-flex min-h-8 items-center justify-center rounded-full border border-border px-3 text-xs font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >إلغاء التحديد</button>

            <button
                type="button"
                @click="openBulkDelete()"
                class="inline-flex min-h-8 items-center justify-center rounded-full bg-destructive px-3 text-xs font-medium text-destructive-foreground transition-colors hover:bg-destructive/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >حذف المحدد</button>
        </div>
    </div>

    <div
        wire:loading
        wire:target="search,refreshResults,conversations-changed"
        class="flex flex-col gap-2 px-1 py-1"
        aria-busy="true"
        aria-label="جارٍ تحديث نتائج البحث"
    >
        <x-conversation.list-skeleton :rows="6" :selectable="true" :show-date="true" />
    </div>

    <div wire:loading.remove wire:target="search,refreshResults,conversations-changed">
        <div
            class="transition-opacity"
            wire:loading.class="opacity-60"
            wire:target="deleteConversations"
        >
        @if ($results->total() > 0)
            @if ($groups['pinned']->isNotEmpty())
                <p class="px-3 pb-1 pt-2 text-[11px] font-medium text-muted-foreground">المثبتة</p>

                <ul role="list" class="space-y-0.5">
                    @foreach ($groups['pinned'] as $conversation)
                        <li wire:key="search-pinned-{{ $conversation->id }}">
                            <x-conversation.list-item
                                :conversation="$conversation"
                                context="search"
                                :show-date="true"
                                :selectable="true"
                            />
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($groups['recent']->isNotEmpty())
                <p class="px-3 pb-1 pt-3 text-[11px] font-medium text-muted-foreground">الأخيرة</p>

                <ul role="list" class="space-y-0.5">
                    @foreach ($groups['recent'] as $conversation)
                        <li wire:key="search-recent-{{ $conversation->id }}">
                            <x-conversation.list-item
                                :conversation="$conversation"
                                context="search"
                                :show-date="true"
                                :selectable="true"
                            />
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <div class="rounded-2xl border border-dashed border-border bg-surface/60 px-6 py-12 text-center">
                @if ($search !== '')
                    <p class="text-sm font-medium text-foreground">لا توجد نتائج مطابقة</p>
                    <p class="mt-1 text-xs text-muted-foreground">لم نعثر على محادثات تطابق «{{ $search }}».</p>
                    <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                        <button
                            type="button"
                            wire:click="clear"
                            @click="clearSelection(); $refs.search?.focus()"
                            class="inline-flex min-h-9 items-center justify-center rounded-xl border border-border px-4 text-xs font-medium text-foreground transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >مسح البحث</button>
                        <a
                            wire:navigate
                            href="{{ route('conversations.create') }}"
                            class="inline-flex min-h-9 items-center justify-center rounded-xl ui-primary-btn px-4 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >محادثة جديدة</a>
                    </div>
                @else
                    <p class="text-sm font-medium text-foreground">لا توجد محادثات بعد</p>
                    <p class="mt-1 text-xs text-muted-foreground">ابدأ محادثة جديدة لتظهر هنا.</p>
                    <a
                        wire:navigate
                        href="{{ route('conversations.create') }}"
                        class="mt-4 inline-flex min-h-9 items-center justify-center rounded-xl ui-primary-btn px-4 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >محادثة جديدة</a>
                @endif
            </div>
        @endif
        </div>
    </div>

    @if ($results->hasMorePages() && $perPage < \App\Services\Conversation\ConversationSearchService::MAX_PER_PAGE)
        <div class="flex justify-center pt-1">
            <button
                type="button"
                wire:click="loadMore"
                wire:loading.attr="disabled"
                wire:target="loadMore"
                class="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-full border border-border px-5 text-xs font-medium text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <span wire:loading wire:target="loadMore" aria-hidden="true">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                </span>
                <span wire:loading.remove wire:target="loadMore">عرض المزيد ({{ $results->total() - count($results->items()) }})</span>
                <span wire:loading wire:target="loadMore">جارٍ التحميل...</span>
            </button>
        </div>
    @elseif ($results->hasMorePages())
        <p class="pt-1 text-center text-xs text-muted-foreground">
            تم عرض أحدث {{ $perPage }} محادثة. استخدم البحث للعثور على المزيد.
        </p>
    @endif

    <x-ui.confirm-modal
        id="search-delete"
        title="حذف المحادثات المحددة"
        message="سيتم حذف المحادثات المحددة ورسائلها نهائياً. لا يمكن التراجع عن هذا الإجراء."
        confirmLabel="حذف"
        cancelLabel="إلغاء"
        :teleport="true"
    >
        <x-slot:confirm>
            <button
                type="button"
                @click="if (busy) return; busy = true; $wire.deleteConversations(payload?.ids || []).finally(() => { busy = false; })"
                :disabled="busy"
                class="inline-flex min-h-[36px] items-center justify-center gap-1.5 rounded-full bg-destructive px-5 py-2 text-xs font-medium text-destructive-foreground transition-colors hover:bg-destructive/90 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span x-show="busy" x-cloak aria-hidden="true">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                </span>
                <span x-text="busy ? 'جارٍ الحذف...' : 'حذف'">حذف</span>
            </button>
        </x-slot:confirm>
    </x-ui.confirm-modal>

    @livewire('conversation.conversation-actions', [
        'context' => 'search',
    ])

</div>
