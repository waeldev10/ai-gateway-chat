@extends('layouts.app')

@section('title', 'إعدادات الشخصيات')

@section('content')
    <div class="mx-auto w-full max-w-2xl px-4 py-8 sm:py-10">
        <header class="mb-7 flex items-start gap-3.5">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl ui-accent-soft text-primary" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z" />
                    <path d="M19 16.5 19.8 19l2.2.8-2.2.8L19 23l-.8-2.4L16 19.8l2.2-.8Z" />
                </svg>
            </span>
            <div>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-foreground">الشخصيات</h1>
                <p class="mt-1.5 text-sm leading-6 text-muted-foreground">اضبط أسلوب المساعد بتعليمات تناسب طريقة عملك.</p>
            </div>
        </header>

        @livewire('persona.persona-settings')
    </div>
@endsection
