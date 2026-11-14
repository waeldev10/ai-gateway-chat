@extends('layouts.auth')
@section('title', 'إنشاء حساب')
@section('content')
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold tracking-tight">إنشاء حساب</h1>
        <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">ابدأ باستخدام دردشة الويب الذكية.</p>
    </div>
    @livewire('auth.register-form')
@endsection
