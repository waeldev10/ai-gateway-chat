@extends('layouts.app')

@section('title', 'الملف الشخصي')

@section('content')
    <div class="mx-auto w-full max-w-2xl px-4 py-8 sm:py-10">
        <header class="mb-7 flex items-start gap-3.5">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl ui-accent-soft text-primary" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M5 21a7 7 0 0 1 14 0" />
                </svg>
            </span>
            <div>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-foreground">الملف الشخصي</h1>
                <p class="mt-1.5 text-sm leading-6 text-muted-foreground">حدّث بياناتك وأدر إعدادات الأمان.</p>
            </div>
        </header>

        @livewire('profile.profile-form')
    </div>
@endsection
