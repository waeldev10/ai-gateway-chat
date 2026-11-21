@props([
    'rows' => 6,
    'selectable' => false,
    'showDate' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-0.5']) }} aria-hidden="true">
    @for ($i = 1; $i <= $rows; $i++)
        <div class="flex items-center">
            @if ($selectable)
                <span class="ms-2 h-4 w-4 shrink-0 animate-pulse rounded border border-border bg-muted"></span>
            @endif

            <div @class([
                'flex min-w-0 flex-1 gap-2 rounded-xl px-2.5 pe-3 md:pe-10',
                'min-h-[40px] items-center py-2' => ! $showDate,
                'min-h-[56px] items-start py-2.5' => $showDate,
            ])>
                <span class="min-w-0 flex-1">
                    <span @class([
                        'block h-3.5 max-w-full animate-pulse rounded bg-muted',
                        'w-3/4' => $i % 3 !== 0,
                        'w-2/3' => $i % 3 === 0,
                    ])></span>

                    @if ($showDate)
                        <span class="mt-1.5 block h-2.5 w-16 animate-pulse rounded bg-muted/80"></span>
                    @endif
                </span>

                <span class="h-4 w-4 shrink-0 animate-pulse rounded-full bg-muted/80"></span>
            </div>
        </div>
    @endfor
</div>
