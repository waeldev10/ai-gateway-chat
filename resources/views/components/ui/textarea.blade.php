@props(['error' => false])
<textarea {{ $attributes->merge(['class' => 'mt-1.5 w-full rounded-xl border bg-surface px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted-foreground transition focus:outline-none focus:ring-2 '.($error ? 'border-destructive focus:border-destructive focus:ring-destructive/20' : 'border-input focus:border-ring focus:ring-ring/20')]) }}>{{ $slot }}</textarea>
