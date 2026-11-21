@extends('layouts.app')

@section('title', 'بحث المحادثات')

@section('header-actions')
    <a
        wire:navigate
        href="{{ route('conversations.create') }}"
        aria-label="محادثة جديدة"
        class="inline-flex min-h-9 items-center gap-1.5 rounded-full ui-primary-btn px-3 text-xs font-medium transition-opacity focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 5v14" />
            <path d="M5 12h14" />
        </svg>
        <span class="hidden sm:inline">محادثة جديدة</span>
    </a>
@endsection

@section('content')

    <div
        x-data="{
            selected: [],
            idsRev: 0,
            visibleIds() {
                this.idsRev;

                return [...document.querySelectorAll('input[data-conversation-select]')]
                    .map((input) => input.value);
            },
            get allChecked() {
                const ids = this.visibleIds();

                return ids.length > 0 && ids.every((id) => this.selected.includes(id));
            },
            get someChecked() {
                const ids = this.visibleIds();

                return ids.length > 0
                    && ! this.allChecked
                    && ids.some((id) => this.selected.includes(id));
            },
            clearSelection() {
                this.selected = [];
            },
            toggleAll(checked) {
                const ids = this.visibleIds();

                if (! checked) {
                    this.selected = this.selected.filter((id) => ! ids.includes(id));

                    return;
                }

                this.selected = [...new Set([...this.selected, ...ids])];
            },
            bulkDeleteTitle(count) {
                return count > 1 ? 'حذف المحادثات المحددة' : 'حذف المحادثة';
            },
            bulkDeleteMessage(count) {
                if (count === 1) {
                    return 'هل أنت متأكد من حذف محادثة واحدة محددة؟';
                }

                if (count === 2) {
                    return 'هل أنت متأكد من حذف محادثتين محددتين؟';
                }

                if (count >= 3 && count <= 10) {
                    return 'هل أنت متأكد من حذف ' + count + ' محادثات محددة؟';
                }

                return 'هل أنت متأكد من حذف ' + count + ' محادثة محددة؟';
            },
            openBulkDelete() {
                const visible = new Set(this.visibleIds());
                const ids = [...new Set(this.selected)].filter((id) => visible.has(id));

                if (ids.length === 0) {
                    return;
                }

                this.selected = ids;

                window.dispatchEvent(new CustomEvent('open-confirm-modal', {
                    detail: {
                        id: 'search-delete',
                        title: this.bulkDeleteTitle(ids.length),
                        message: this.bulkDeleteMessage(ids.length),
                        subject: '',
                        payload: { ids: ids },
                    },
                }));
            },
        }"
        x-init="new MutationObserver(() => { idsRev++ }).observe($el, { childList: true, subtree: true })"
        @conversations-changed.window="clearSelection()"
        class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6"
    >
        @if (request()->header('X-Livewire-Navigate'))
            <livewire:conversation.conversation-search :search="is_string(request()->query('search')) ? request()->query('search') : ''" />
        @else
            <livewire:conversation.conversation-search lazy :search="is_string(request()->query('search')) ? request()->query('search') : ''" />
        @endif
    </div>
    
@endsection
