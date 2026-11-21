@extends('layouts.app')

@section('title', e(filled($conversation->title) ? $conversation->title : 'محادثة جديدة'))

@section('header-actions')
    @livewire('conversation.conversation-actions', ['conversationId' => (string) $conversation->id, 'context' => 'page'])
@endsection

@section('content')
    <div class="flex min-h-0 flex-1 flex-col">
        @livewire('chat.conversation-chat', ['conversationId' => (string) $conversation->id])
    </div>
@endsection
