@props(['variant' => 'primary', 'type' => 'button'])
@php
    $classes = $variant === 'primary'
        ? 'rounded-full ui-primary-btn px-5 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
        : 'rounded-full border border-border px-4 py-2 text-sm hover:bg-muted transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';
@endphp
<button {{ $attributes->merge(['type' => $type, 'class' => $classes]) }}>{{ $slot }}</button>
