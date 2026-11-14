@extends('layouts.app')

@section('title', 'محادثة جديدة')

@section('content')

    <div class="flex flex-col h-full min-h-0" data-create-root>

        <div class="flex-1 min-h-0 overflow-y-auto" data-chat-scroll>

            <div class="max-w-3xl mx-auto px-4 py-8" data-create-content>
                <div data-create-hero class="flex flex-col items-center justify-center text-center px-4 py-10 min-h-[50vh]">
                    <div class="w-12 h-12 rounded-2xl ui-primary-surface grid place-items-center text-lg mb-4">◐</div>
                    <h1 class="text-2xl font-semibold">Laravel AI Hub</h1>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">كيف يمكنني مساعدتك اليوم؟</p>
                </div>
                <div data-chat-stream-slot class="space-y-6"></div>
                <div data-create-composer-slot class="mt-6">
                    {{-- @livewire('chat.message-composer', ['centered' => true]) --}}
                </div>
            </div>

        </div>

        <div class="hidden" aria-hidden="true">
            <span>لا توجد رسائل بعد</span>
        </div>

    </div>

@endsection