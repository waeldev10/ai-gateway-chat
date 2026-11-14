
<div>
<form wire:submit="register" class="space-y-4">
    <div>
        <x-ui.label for="name">الاسم</x-ui.label>
        <x-ui.input id="name" type="text" wire:model="name" autofocus autocomplete="name" :error="$errors->has('name')" placeholder="اسمك الكامل" />
        <x-ui.input-error for="name" />
    </div>
    <div>
        <x-ui.label for="email">البريد الإلكتروني</x-ui.label>
        <x-ui.input id="email" type="email" wire:model="email" autocomplete="email" :error="$errors->has('email')" placeholder="name@example.com" />
        <x-ui.input-error for="email" />
    </div>
    <div>
        <x-ui.label for="password">كلمة المرور</x-ui.label>
        <x-ui.password-input id="password" wire:model="password" autocomplete="new-password" :error="$errors->has('password')" placeholder="••••••••" />
        <x-ui.input-error for="password" />
    </div>
    <div>
        <x-ui.label for="password_confirmation">تأكيد كلمة المرور</x-ui.label>
        <x-ui.password-input id="password_confirmation" wire:model="password_confirmation" autocomplete="new-password" placeholder="••••••••" />
    </div>
    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="register" class="w-full inline-flex items-center justify-center gap-1.5">
        <span wire:loading wire:target="register">
            <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        </span>
        <span wire:loading.remove wire:target="register">إنشاء حساب</span>
        <span wire:loading wire:target="register">جاري إنشاء الحساب...</span>
    </x-ui.button>
</form>
<p class="mt-6 text-center text-sm text-zinc-600 dark:text-zinc-300">لديك حساب بالفعل؟ <a wire:navigate href="{{ route('login') }}" class="font-medium ui-link underline underline-offset-4">تسجيل الدخول</a></p>
</div>
