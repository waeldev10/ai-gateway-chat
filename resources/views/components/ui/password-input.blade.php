{{-- Password input with an Alpine-only show/hide eye toggle.
     Styling mirrors `components/input` (same light/dark/error states) plus
     inline-end padding for the icon. Each instance owns its own `show` flag,
     so fields toggle independently. Toggling only flips the input `type` —
     the value is untouched and no Livewire request is sent (no `wire:`
     directives in here). Button is keyboard-focusable with proper ARIA. --}}
@props(['error' => false, 'showLabel' => 'إظهار كلمة المرور', 'hideLabel' => 'إخفاء كلمة المرور'])
<div x-data="{ show: false }" class="relative mt-1.5">
    <input {{ $attributes->except(['type'])->merge(['class' => 'w-full rounded-xl border bg-surface px-3.5 py-2.5 pe-10 text-sm text-foreground placeholder:text-muted-foreground transition focus:outline-none focus:ring-2 '.($error ? 'border-destructive focus:border-destructive focus:ring-destructive/20' : 'border-input focus:border-ring focus:ring-ring/20')]) }} type="password" :type="show ? 'text' : 'password'" />
    <button type="button" @click="show = !show" :aria-pressed="show ? 'true' : 'false'" :aria-label="show ? @js($hideLabel) : @js($showLabel)"
        class="absolute inset-y-0 end-0 flex items-center pe-3 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring rounded">
        <svg x-show="!show" class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
        <svg x-show="show" x-cloak class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68" /><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61" /><line x1="2" y1="2" x2="22" y2="22" /><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" /></svg>
    </button>
</div>
