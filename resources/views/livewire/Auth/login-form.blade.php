
<div>
    <form wire:submit="login" class="space-y-4">
        <div>
            <x-ui.label for="email">البريد الإلكتروني</x-ui.label>
            <x-ui.input id="email" type="email" wire:model="email" autofocus autocomplete="email" :error="$errors->has('email')" placeholder="name@example.com" />
            <x-ui.input-error for="email" />
        </div>
        <div>
            <div class="flex items-center justify-between gap-2">
                <x-ui.label for="password">كلمة المرور</x-ui.label>
                {{-- @if(Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-zinc-500 dark:text-zinc-400 underline underline-offset-4 hover:text-black dark:hover:text-white">نسيت كلمة المرور؟</a>
                @endif --}}
            </div>
            <x-ui.password-input id="password" wire:model="password" autocomplete="current-password" :error="$errors->has('password')" placeholder="••••••••" />
            <x-ui.input-error for="password" />
        </div>
        <div class="flex items-center gap-2">
            <input id="remember" type="checkbox" wire:model="remember" class="h-4 w-4 rounded border-black/20 dark:border-white/20 bg-transparent ui-checkbox">
            <label for="remember" class="text-sm text-zinc-600 dark:text-zinc-300">تذكرني</label>
        </div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="login" class="w-full inline-flex items-center justify-center gap-1.5">
            <span wire:loading wire:target="login">
                <svg class="animate-spin" width="1em" height="1em" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            </span>
            <span wire:loading.remove wire:target="login">تسجيل الدخول</span>
            <span wire:loading wire:target="login">جاري تسجيل الدخول...</span>
        </x-ui.button>
    </form>
    <p class="mt-6 text-center text-sm text-zinc-600 dark:text-zinc-300">ليس لديك حساب؟ 
        <a wire:navigate href="{{ route('register') }}" class="font-medium ui-link underline underline-offset-4">إنشاء حساب</a>
    </p>
</div>
