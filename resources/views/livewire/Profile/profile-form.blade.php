{{-- Personal profile: header from Livewire state plus two forms.
     Tab switching is Alpine-only so it never costs a server request.
     Both saves are single Livewire actions with unified toasts. --}}
<div x-data="{ tab: 'profile' }">
    <div class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
        <div class="flex items-center gap-3.5">
            <span class="w-14 h-14 rounded-full ui-primary-surface grid place-items-center text-xl font-semibold shrink-0" aria-hidden="true">{{ mb_substr($name !== '' ? $name : 'م', 0, 1) }}</span>
            <span class="flex-1 min-w-0">
                <span class="block text-base font-semibold truncate">{{ $name }}</span>
                <span class="block text-xs text-muted-foreground truncate mt-0.5" dir="ltr">{{ $email }}</span>
            </span>
        </div>

        <div class="my-5 h-px bg-border" role="separator"></div>

        <div class="grid grid-cols-2 gap-1 rounded-xl bg-muted p-1" role="tablist" aria-label="أقسام الملف الشخصي">
            <button type="button" role="tab" :aria-selected="tab === 'profile' ? 'true' : 'false'" @click="tab = 'profile'"
                :class="tab === 'profile' ? 'bg-surface text-foreground shadow' : 'text-muted-foreground hover:bg-surface/60'"
                class="rounded-lg px-2 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">بيانات الحساب</button>
            <button type="button" role="tab" :aria-selected="tab === 'password' ? 'true' : 'false'" @click="tab = 'password'"
                :class="tab === 'password' ? 'bg-surface text-foreground shadow' : 'text-muted-foreground hover:bg-surface/60'"
                class="rounded-lg px-2 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">كلمة المرور</button>
        </div>

        <form wire:submit="saveProfile" x-show="tab === 'profile'" class="space-y-4 mt-5" role="tabpanel" aria-label="بيانات الحساب">
            <div>
                <x-ui.label for="profile-name">الاسم</x-ui.label>
                <x-ui.input id="profile-name" type="text" wire:model="name" :error="$errors->has('name')" autocomplete="name" maxlength="255" />
                <x-ui.input-error for="name" />
            </div>
            <div>
                <x-ui.label for="profile-email">البريد الإلكتروني</x-ui.label>
                <x-ui.input id="profile-email" type="email" dir="ltr" wire:model="email" :error="$errors->has('email')" autocomplete="email" maxlength="255" class="text-left" />
                <x-ui.input-error for="email" />
            </div>
            <div class="flex justify-end pt-1">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveProfile" class="inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="saveProfile">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span wire:loading.remove wire:target="saveProfile">حفظ التغييرات</span>
                    <span wire:loading wire:target="saveProfile">جارٍ الحفظ...</span>
                </x-ui.button>
            </div>
        </form>

        <form wire:submit="savePassword" x-show="tab === 'password'" x-cloak class="space-y-4 mt-5" role="tabpanel" aria-label="كلمة المرور">
            <div>
                <x-ui.label for="password-current">كلمة المرور الحالية</x-ui.label>
                <x-ui.password-input id="password-current" wire:model="current_password" :error="$errors->has('current_password')" autocomplete="current-password" />
                <x-ui.input-error for="current_password" />
            </div>
            <div>
                <x-ui.label for="password-new">كلمة المرور الجديدة</x-ui.label>
                <x-ui.password-input id="password-new" wire:model="password" :error="$errors->has('password')" autocomplete="new-password" />
                <x-ui.input-error for="password" />
            </div>
            <div>
                <x-ui.label for="password-confirm">تأكيد كلمة المرور الجديدة</x-ui.label>
                <x-ui.password-input id="password-confirm" wire:model="password_confirmation" autocomplete="new-password" />
            </div>
            <div class="flex justify-end pt-1">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePassword" class="inline-flex items-center gap-1.5">
                    <span wire:loading wire:target="savePassword">
                        <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    </span>
                    <span wire:loading.remove wire:target="savePassword">تحديث كلمة المرور</span>
                    <span wire:loading wire:target="savePassword">جارٍ الحفظ...</span>
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
