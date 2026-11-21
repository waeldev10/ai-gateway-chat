<div
    class="transition-opacity"
    wire:loading.class="opacity-60"
    wire:target="togglePin,rename,delete"
    @conversation-pin-requested.window="if ($event.detail.scope === @js($context)) { $wire.togglePin($event.detail.id).catch(() => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', title: 'تعذر تحديث التثبيت', message: 'حاول مرة أخرى.' } }))) }"
    @conversation-rename-requested.window="if ($event.detail.scope === @js($context)) { $wire.rename($event.detail.id, $event.detail.title).catch(() => window.dispatchEvent(new CustomEvent('conversation-rename-failed', { detail: { scope: @js($context), message: 'تعذر حفظ الاسم. حاول مرة أخرى.' } }))) }"
    @conversation-delete-requested.window="if ($event.detail.scope === @js($context)) { window.dispatchEvent(new CustomEvent('open-confirm-modal', { detail: { id: @js($deleteModalId), title: 'حذف المحادثة', subject: $event.detail.title || '', payload: { ids: [$event.detail.id] } } })) }"
>
    @if ($conversationId !== null)
        <x-conversation.actions-menu
            :id="$conversationId"
            :title="$title"
            :pinned="$isPinned"
            :url="$url"
            :context="$context"
            :reveal="false"
        />
    @endif

    <x-conversation.rename-modal :context="$context" />

    <x-ui.confirm-modal
        :id="$deleteModalId"
        title="حذف المحادثة"
        message="سيتم حذف هذه المحادثة ورسائلها نهائياً. لا يمكن التراجع عن هذا الإجراء."
        confirmLabel="حذف"
        cancelLabel="إلغاء"
        :teleport="true"
    >
        <x-slot:confirm>
            <button
                type="button"
                @click="if (busy) return; busy = true; $wire.delete(payload?.ids?.[0] ?? null).finally(() => { busy = false; })"
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
</div>
