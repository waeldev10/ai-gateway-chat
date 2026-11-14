{{-- Persona settings: the system default is always active and read-only.
     The user's own personas can be created up to the configured limit and
     each one toggles independently. Every mutation is a single Livewire
     action with unified toasts and two-step delete. --}}
<div class="space-y-4">
    @if($this->systemDefault !== null)
        <div class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-sm font-semibold">الشخصية الافتراضية</h2>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium bg-muted text-muted-foreground">النظام</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium bg-success/15 text-success">نشطة دائمًا</span>
            </div>
            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">تُستخدم هذه الشخصية عند عدم وجود شخصية مخصصة نشطة، ولا يمكن تعديلها أو حذفها.</p>
            <div class="mt-3 rounded-xl bg-muted px-3.5 py-3">
                <p class="text-xs font-medium">{{ $this->systemDefault->name }}</p>
                <p class="mt-1 text-xs text-muted-foreground leading-relaxed whitespace-pre-wrap break-words" dir="auto">{{ $this->systemDefault->system_prompt }}</p>
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <h2 class="text-sm font-semibold">الشخصيات المخصصة</h2>
        <p class="mt-1 text-xs text-muted-foreground leading-relaxed">يمكنك تفعيل أكثر من شخصية مخصصة في الوقت نفسه، وسيتم استخدام أحدث شخصية نشطة عند بدء محادثة جديدة.</p>

        @if($this->personas->isEmpty())
            <p class="mt-4 text-xs text-muted-foreground leading-relaxed">لا توجد شخصيات مخصصة بعد. أضف شخصيتك الأولى من النموذج أدناه.</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach($this->personas as $persona)
                    <li wire:key="persona-{{ $persona->id }}" class="rounded-xl border border-border px-3.5 py-3" data-persona-id="{{ $persona->id }}">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="flex-1 min-w-0 text-xs font-semibold truncate">{{ $persona->name }}</span>
                            @if($persona->is_active)
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium bg-success/15 text-success">مفعّلة</span>
                            @else
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium bg-muted text-muted-foreground">معطّلة</span>
                            @endif
                        </div>

                        @if($editingId === (string) $persona->id)
                            <form wire:submit="update" class="mt-3 space-y-3">
                                <div>
                                    <x-ui.label for="persona-edit-name-{{ $persona->id }}">الاسم</x-ui.label>
                                    <x-ui.input id="persona-edit-name-{{ $persona->id }}" type="text" wire:model="editingName" :error="$errors->has('editingName')" maxlength="{{ $this->maxNameLength }}" />
                                    <x-ui.input-error for="editingName" />
                                </div>
                                <div>
                                    <x-ui.label for="persona-edit-prompt-{{ $persona->id }}">تعليمات الشخصية</x-ui.label>
                                    <x-ui.textarea id="persona-edit-prompt-{{ $persona->id }}" wire:model="editingSystemPrompt" :error="$errors->has('editingSystemPrompt')" rows="4" maxlength="{{ $this->maxPromptLength }}" dir="auto" />
                                    <x-ui.input-error for="editingSystemPrompt" />
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="cancelEdit"
                                        class="px-4 py-2 rounded-full border border-border text-xs font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring min-h-[36px] transition-colors">إلغاء</button>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="update"
                                        class="px-4 py-2 rounded-full ui-primary-btn text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed min-h-[36px] transition-colors inline-flex items-center gap-1.5">
                                        <span wire:loading wire:target="update">
                                            <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                                        </span>
                                        <span wire:loading.remove wire:target="update">حفظ التعديلات</span>
                                        <span wire:loading wire:target="update">جارٍ الحفظ...</span>
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="mt-1.5 text-xs text-muted-foreground leading-relaxed whitespace-pre-wrap break-words" dir="auto">{{ \Illuminate\Support\Str::limit($persona->system_prompt, 220) }}</p>
                            <div class="mt-2.5 flex items-center gap-1.5 flex-wrap">
                                @if($persona->is_active)
                                    <button type="button" wire:click="toggle('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium border border-border text-muted-foreground hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring min-h-[32px] transition-colors">تعطيل</button>
                                @else
                                    <button type="button" wire:click="toggle('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium ui-primary-btn min-h-[32px] transition-colors">تفعيل</button>
                                @endif
                                <button type="button" wire:click="startEdit('{{ $persona->id }}')"
                                    class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-muted-foreground hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring min-h-[32px] transition-colors">تعديل</button>
                                @if($confirmingId === (string) $persona->id)
                                    <button type="button" wire:click="remove('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium bg-destructive text-destructive-foreground hover:opacity-90 min-h-[32px] transition-colors">تأكيد الحذف</button>
                                    <button type="button" wire:click="$set('confirmingId', null)"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-muted-foreground hover:bg-muted min-h-[32px] transition-colors">إلغاء</button>
                                @else
                                    <button type="button" wire:click="remove('{{ $persona->id }}')"
                                        class="inline-flex items-center rounded-full px-3 py-1.5 text-[11px] font-medium text-destructive hover:bg-destructive/10 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring min-h-[32px] transition-colors">حذف</button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <h2 class="text-sm font-semibold">إضافة شخصية</h2>
        @if($this->canCreate)
            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">مثال: مُراجع شيفرة يركز على الأمان والوضوح. يمكنك إنشاء {{ $this->maxCustom }} شخصيات مخصصة كحد أقصى.</p>
            <form wire:submit="create" class="mt-4 space-y-4">
                <div>
                    <x-ui.label for="persona-name">الاسم</x-ui.label>
                    <x-ui.input id="persona-name" type="text" wire:model="name" :error="$errors->has('name')" maxlength="{{ $this->maxNameLength }}" placeholder="اسم الشخصية" />
                    <x-ui.input-error for="name" />
                </div>
                <div>
                    <x-ui.label for="persona-prompt">تعليمات الشخصية</x-ui.label>
                    <x-ui.textarea id="persona-prompt" wire:model="system_prompt" :error="$errors->has('system_prompt')" rows="4" maxlength="{{ $this->maxPromptLength }}" dir="auto" placeholder="صف كيف يجب أن تتحدث الشخصية وتجيب..." />
                    <x-ui.input-error for="system_prompt" />
                </div>
                <div class="flex justify-end pt-1">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create" class="inline-flex items-center gap-1.5">
                        <span wire:loading wire:target="create">
                            <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        </span>
                        <span wire:loading.remove wire:target="create">إضافة الشخصية</span>
                        <span wire:loading wire:target="create">جارٍ الإنشاء...</span>
                    </x-ui.button>
                </div>
            </form>
        @else
            <p class="mt-3 rounded-xl bg-muted px-3.5 py-3 text-xs text-muted-foreground leading-relaxed">لقد وصلت إلى الحد الأقصى: يمكنك إنشاء {{ $this->maxCustom }} شخصيات مخصصة كحد أقصى. احذف إحدى الشخصيات الحالية لإضافة شخصية جديدة.</p>
        @endif
    </div>
</div>
