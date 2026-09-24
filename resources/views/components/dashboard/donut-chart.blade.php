@props([
    'items' => [],
    'testId' => 'donut-chart',
    'emptyMessage' => 'Sin datos para los filtros seleccionados.',
])

@php
    $colors = ['#006a61', '#2563eb', '#d97706', '#e11d48', '#7c3aed', '#0891b2', '#65a30d', '#ea580c'];
    $total = (int) collect($items)->sum('cantidad');
    $totalLabel = $total === 1 ? 'causa' : 'causas';
    $offset = 0.0;
    $segments = collect($items)->values()->map(function (array $item, int $index) use ($colors, $total, &$offset): array {
        $percentage = $total > 0 ? round(((int) $item['cantidad'] / $total) * 100, 4) : 0.0;
        $segment = [
            ...$item,
            'color' => $colors[$index % count($colors)],
            'percentage' => $percentage,
            'offset' => $offset,
        ];
        $offset += $percentage;

        return $segment;
    });
@endphp

<div data-testid="{{ $testId }}" class="grid gap-5 sm:grid-cols-[12rem_minmax(0,1fr)] sm:items-center">
    <div class="relative mx-auto size-48" wire:loading.class="opacity-50">
        <svg viewBox="0 0 100 100" class="size-full" role="img" aria-labelledby="{{ $testId }}-title {{ $testId }}-description">
            <title id="{{ $testId }}-title">Gráfico circular con {{ $total }} {{ $totalLabel }}</title>
            <desc id="{{ $testId }}-description">Distribución porcentual según los filtros seleccionados.</desc>
            <circle cx="50" cy="50" r="42" fill="none" stroke-width="14" class="stroke-zinc-200 dark:stroke-slate-700" />
            @foreach ($segments as $segment)
                @if ($segment['percentage'] > 0)
                    <circle
                        wire:key="{{ $testId }}-segment-{{ $segment['id'] ?? $loop->index }}"
                        cx="50"
                        cy="50"
                        r="42"
                        fill="none"
                        stroke="{{ $segment['color'] }}"
                        stroke-width="14"
                        pathLength="100"
                        stroke-dasharray="{{ $segment['percentage'] }} {{ 100 - $segment['percentage'] }}"
                        stroke-dashoffset="{{ -$segment['offset'] }}"
                        transform="rotate(-90 50 50)"
                    />
                @endif
            @endforeach
            <text x="50" y="47" text-anchor="middle" dominant-baseline="middle" class="fill-[#091426] text-[1rem] font-semibold dark:fill-slate-100">{{ $total }}</text>
            <text x="50" y="60" text-anchor="middle" dominant-baseline="middle" class="fill-zinc-500 text-[0.45rem] font-medium uppercase dark:fill-zinc-400">{{ $totalLabel }}</text>
        </svg>
    </div>

    <div class="grid min-w-0 gap-3">
        @forelse ($segments as $segment)
            <div wire:key="{{ $testId }}-legend-{{ $segment['id'] ?? $loop->index }}" class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 text-sm">
                <svg viewBox="0 0 12 12" class="size-3 shrink-0" aria-hidden="true"><circle cx="6" cy="6" r="5" fill="{{ $segment['color'] }}" /></svg>
                <span class="truncate font-medium" title="{{ $segment['nombre'] }}">{{ $segment['nombre'] }}</span>
                <span class="whitespace-nowrap text-right text-zinc-600 dark:text-zinc-300">
                    {{ $segment['cantidad'] }} · {{ number_format($segment['percentage'], 1, ',', '.') }}%
                </span>
            </div>
        @empty
            <flux:text>{{ $emptyMessage }}</flux:text>
        @endforelse
    </div>
</div>
