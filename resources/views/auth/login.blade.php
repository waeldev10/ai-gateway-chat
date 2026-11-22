@extends('layouts.auth')

@section('title', 'تسجيل الدخول')

@section('content')

    <div class="mb-7 text-center">
        <h1 class="text-3xl font-semibold tracking-tight text-foreground">مرحبا بعودتك</h1>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">سجل الدخول إلى بوابة الذكاء الاصطناعي للمتابعة</p>
    </div>
    
    @livewire('auth.login-form')

@endsection
