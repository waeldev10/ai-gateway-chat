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

<body class="auth-page-ambient text-foreground antialiased min-h-screen">

    <x-ui.toast />

    <main class="min-h-screen w-full flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-7 flex items-center justify-center gap-3">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-muted text-primary" aria-hidden="true">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z" />
                        <path d="M19 16.5 19.8 19l2.2.8-2.2.8L19 23l-.8-2.4L16 19.8l2.2-.8Z" />
                    </svg>
                </span>
                <span class="font-semibold tracking-tight text-xl">بوابة الذكاء الاصطناعي</span>
            </div>
            <div class="auth-card rounded-3xl border p-6 sm:p-8">
                @yield('content')
            </div>
            <p class="mt-6 text-center text-xs text-muted-foreground">محادثاتك وأفكارك في مساحة واحدة.</p>
            <div class="mt-4 flex justify-center">
                <x-theme.theme-toggle />
            </div>
        </div>

    </main>

    @livewireScripts
    @stack('scripts')
</body>

</html>
