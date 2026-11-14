<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @include('components.theme.theme-init')
    @include('components.theme.ui-colors')
    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js']) @endif
    @livewireStyles
    @stack('css')
</head>

<body class="bg-background text-foreground antialiased min-h-screen">

    <x-ui.toast />

    <main class="min-h-screen w-full flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="flex items-center justify-center gap-2.5 mb-6">
                <span class="w-10 h-10 rounded-2xl ui-primary-surface grid place-items-center text-lg">◐</span>
                <span class="font-semibold tracking-tight text-lg">{{ config('app.name') }}</span>
            </div>
            <div class="rounded-2xl border border-border bg-surface p-6 sm:p-8 shadow-sm">
                @yield('content')
            </div>
            <p class="mt-6 text-center text-xs text-muted-foreground">مساحة دردشة نظيفة — نفس هوية التطبيق في الوضع الفاتح والداكن.</p>
            <div class="mt-4 flex justify-center">
                <x-theme.theme-toggle />
            </div>
        </div>

    </main>

    @livewireScripts
    @stack('scripts')
</body>

</html>
