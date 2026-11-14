@extends('layouts.app')

@section('title', 'الملف الشخصي')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-8 w-full">
        <h1 class="text-xl font-semibold mb-4">الملف الشخصي</h1>

        @livewire('profile.profile-form')
    </div>
@endsection
