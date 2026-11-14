@extends('layouts.app')

@section('title', 'إعدادات الشخصيات')

@section('content')
    <div class="max-w-xl mx-auto px-4 py-8 w-full">
        <h1 class="text-xl font-semibold mb-4">الشخصيات</h1>

        @livewire('persona.persona-settings')
    </div>
@endsection
