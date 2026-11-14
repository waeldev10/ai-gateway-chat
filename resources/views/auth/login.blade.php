@extends('layouts.auth')

@section('title', 'تسجيل الدخول')

@section('content')

    <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold tracking-tight">تسجيل الدخول</h1>
        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">مرحباً بعودتك إلى {{ config('app.name') }}.</p>
    </div>
    
    @livewire('auth.login-form')

@endsection
