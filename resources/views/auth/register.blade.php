@extends('layouts.auth')
@section('title', 'إنشاء حساب')
@section('content')
    <div class="mb-7 text-center">
        <h1 class="text-3xl font-semibold tracking-tight text-foreground">أنشئ حسابك</h1>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">ابدأ مساحة عملك واحتفظ بمحادثاتك في مكان واحد.</p>
    </div>
    @livewire('auth.register-form')
@endsection
