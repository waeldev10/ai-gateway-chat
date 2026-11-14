<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name'))</title>

        <meta name="description" content="@yield('meta_description', 'وصف الموقع الافتراضي هنا')">
        <meta name="robots" content="@yield('meta_robots', 'index, follow')">
        <link rel="canonical" href="@yield('canonical', url()->current())"> {{-- Language --}}
        <meta http-equiv="content-language" content="{{ app()->getLocale() }}"> {{-- Open Graph / Facebook / WhatsApp --}}
        <meta property="og:type" content="@yield('og_type', 'website')">
        <meta property="og:title" content="@yield('og_title', trim($__env->yieldContent('title', config('app.name'))))">
        <meta property="og:description" content="@yield('og_description', trim($__env->yieldContent('meta_description', 'وصف الموقع الافتراضي هنا')))">
        <meta property="og:url" content="@yield('canonical', url()->current())">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_AR' : 'en_US' }}"> @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')"> @endif {{-- Twitter / X --}}
        <meta name="twitter:card" content="@yield('twitter_card', 'summary_large_image')">
        <meta name="twitter:title" content="@yield('twitter_title', trim($__env->yieldContent('title', config('app.name'))))">
        <meta name="twitter:description" content="@yield('twitter_description', trim($__env->yieldContent('meta_description', 'وصف الموقع الافتراضي هنا')))"> @hasSection('twitter_image')
        <meta name="twitter:image" content="@yield('twitter_image')"> @elseif(View::hasSection('og_image'))
        <meta name="twitter:image" content="@yield('og_image')"> @endif {{-- Favicon --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}">

        @include('components.theme.theme-init')
        @include('components.theme.ui-colors')

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
        @stack('css')
    </head>

    <body
        x-data="{
            // Single application-level UI scope shared by the sidebar, its
            // user menu and the header controls. Persisted desktop collapse
            // state; the mobile drawer and the user menu stay independent.
            sidebarOpen: (() => {
                try {
                    return JSON.parse(localStorage.getItem('sidebarOpen')) !== false;
                } catch (e) {
                    return true;
                }
            })(),
            drawer: false,
            userMenu: false,
            theme: window.__currentTheme ? window.__currentTheme() : 'system',
            toggleSidebar() {
                this.sidebarOpen = !this.sidebarOpen;
                try {
                    localStorage.setItem('sidebarOpen', JSON.stringify(this.sidebarOpen));
                } catch (e) {}
            },
            setTheme(value) {
                if (value !== 'light' && value !== 'dark' && value !== 'system') {
                    return;
                }
                this.theme = value;
                if (window.__setTheme) {
                    window.__setTheme(value);
                }
                this.$dispatch('theme-changed', { theme: value });
            },
        }"
        @storage.window="if ($event.key === 'theme') { theme = window.__currentTheme ? window.__currentTheme() : 'system'; }"
        @theme-changed.window="theme = $event.detail.theme"
        @keydown.escape.window="if (drawer || userMenu) { drawer = false; userMenu = false; }"
        @@livewire:navigated.window="drawer = false; userMenu = false;"
        class="bg-background text-foreground antialiased h-screen flex overflow-hidden">
        
        @include('components.sidebar.sidebar')

        <x-ui.toast />

        <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-surface overflow-hidden">
            <header class="h-14 flex items-center gap-3 px-4 border-b border-border shrink-0">
                <button type="button" @click="drawer = true; userMenu = false"
                    aria-label="فتح القائمة" aria-controls="chat-sidebar" :aria-expanded="drawer ? 'true' : 'false'"
                    class="lg:hidden w-9 h-9 -ms-1 grid place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </button>
                <h1 class="font-medium truncate text-sm"
                    @conversation-renamed.window="$el.textContent = $event.detail.title; document.title = $event.detail.title">
                    @yield('title')
                </h1>
                <div class="ms-auto flex items-center gap-1 shrink-0">
                    @yield('header-actions')
                    <button type="button" @click="$dispatch('open-settings-modal')" aria-label="الإعدادات" aria-haspopup="dialog"
                        class="w-9 h-9 grid place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
                        </svg>
                    </button>
                </div>
            </header>
            <main class="flex-1 min-h-0 overflow-y-auto flex flex-col bg-background">
                @yield('content')
            </main>
        </div>

        <x-settings.ui-color-settings />

        @livewireScripts
        @stack('scripts')
    </body>

</html>