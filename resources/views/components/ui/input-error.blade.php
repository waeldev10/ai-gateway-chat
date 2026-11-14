@props(['for' => null])
@error($for)<p {{ $attributes->merge(['class' => 'mt-1.5 text-xs text-destructive']) }}>{{ $message }}</p>@enderror
