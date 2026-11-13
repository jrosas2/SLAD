<?php

use App\Livewire\Monitoreo\ComponenteConDescartePjud;
use App\Enums\Permiso;
use App\Models\PjudMovimiento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Title('Monitoreo de causas')] class extends ComponenteConDescartePjud
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $tribunal = '';

    #[Url(except: '')]
    public string $responsable = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    #[Locked]
    public bool $imprimir = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permiso::MonitoreoVer->value), 403);
        $this->imprimir = request()->boolean('imprimir');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'tribunal', 'responsable', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function puedeVerTodas(): bool
    {
        return (bool) auth()->user()?->can(Permiso::MonitoreoVerTodas->value);
    }

    /** @return Builder<PjudMovimiento> */
    private function visibles(): Builder
    {
        return PjudMovimiento::query()->visibleFor(auth()->user());
    }

    /** @return LengthAwarePaginator<PjudMovimiento> */
    #[Computed]
    public function movimientos(): LengthAwarePaginator
    {
        return $this->consultaMovimientos()->paginate(25);
    }

    /**
     * Todos los movimientos que cumplen los filtros (para imprimir).
     *
     * @return Collection<int, PjudMovimiento>
     */
    #[Computed]
    public function movimientosImpresion(): Collection
    {
        return $this->imprimir ? $this->consultaMovimientos()->limit(5000)->get() : new Collection;
    }

    /** @return list<string> */
    #[Computed]
    public function filtrosImpresion(): array
    {
        $tribunal = collect($this->tribunales)->firstWhere('codigo', (int) $this->tribunal);
        $responsable = $this->responsable === 'sin_responsable' ? 'Sin responsable' : User::query()->whereKey($this->responsable)->value('name');

        return array_values(array_filter([
            trim($this->search) !== '' ? 'Búsqueda: '.trim($this->search) : null,
            $this->tribunal !== '' ? 'Tribunal: '.($tribunal['nombre'] ?? $this->tribunal) : null,
            $this->puedeVerTodas && $this->responsable !== '' ? 'Responsable: '.($responsable ?? $this->responsable) : null,
            $this->desde !== '' ? 'Desde: '.CarbonImmutable::parse($this->desde)->format('d-m-Y') : null,
            $this->hasta !== '' ? 'Hasta: '.CarbonImmutable::parse($this->hasta)->format('d-m-Y') : null,
        ]));
    }

    /** @return Builder<PjudMovimiento> */
    private function consultaMovimientos(): Builder
    {
        return $this->visibles()
            ->with(['causa:id,nombre,numero_causa,responsable_id,deleted_at', 'causa.responsable:id,codigo,name'])
            ->when($this->tribunal !== '', fn (Builder $query) => $query->where('tribunal_pjud_codigo', $this->tribunal))
            ->when($this->desde !== '', fn (Builder $query) => $query->whereDate('fecha_estado_diario', '>=', $this->desde))
            ->when($this->hasta !== '', fn (Builder $query) => $query->whereDate('fecha_estado_diario', '<=', $this->hasta))
            ->when($this->puedeVerTodas && $this->responsable === 'sin_responsable', fn (Builder $query) => $query->whereHas('causa', fn (Builder $causa) => $causa->whereNull('responsable_id')))
            ->when($this->puedeVerTodas && $this->responsable !== '' && $this->responsable !== 'sin_responsable', fn (Builder $query) => $query->whereHas('causa', fn (Builder $causa) => $causa->where('responsable_id', $this->responsable)))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';
                $query->where(fn (Builder $busqueda) => $busqueda
                    ->where('numero_ingreso', 'like', $search)
                    ->orWhere('caratulado', 'like', $search)
                    ->orWhere('tramite', 'like', $search)
                    ->orWhereHas('causa', fn (Builder $causa) => $causa
                        ->where('numero_causa', 'like', $search)
                        ->orWhere('nombre', 'like', $search)));
            })
            ->orderByDesc('fecha_estado_diario')
            ->orderBy('tribunal_nombre')
            ->orderByDesc('id');
    }

    /**
     * Cantidad de movimientos por día en la página actual (encabezados de grupo).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function movimientosPorDia(): array
    {
        return $this->movimientos->getCollection()
            ->countBy(fn (PjudMovimiento $movimiento) => $movimiento->fecha_estado_diario->toDateString())
            ->all();
    }

    /** @return list<array{codigo: int, nombre: string}> */
    #[Computed]
    public function tribunales(): array
    {
        return $this->visibles()
            ->select(['tribunal_pjud_codigo', 'tribunal_nombre'])
            ->distinct()
            ->orderBy('tribunal_nombre')
            ->get()
            ->unique('tribunal_pjud_codigo')
            ->map(fn (PjudMovimiento $movimiento) => ['codigo' => $movimiento->tribunal_pjud_codigo, 'nombre' => $movimiento->tribunal_nombre])
            ->values()
            ->all();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function responsables(): Collection
    {
        return User::query()
            ->select(['id', 'codigo', 'name'])
            ->whereHas('causasAsignadas', fn (Builder $causa) => $causa->whereHas('pjudMovimientos'))
            ->orderBy('name')
            ->get();
    }

    protected function despuesDeDescartar(): void
    {
        unset($this->movimientos, $this->movimientosPorDia, $this->tribunales, $this->responsables);
    }

    public function limpiarFiltros(): void
    {
        $this->reset('search', 'tribunal', 'responsable', 'desde', 'hasta');
        $this->resetPage();
    }
}; ?>

<div class="monitoreo-pagina flex w-full flex-1 flex-col gap-6">
    @if ($imprimir)
        <div x-data x-init="window.setTimeout(() => window.print(), 400)" wire:ignore></div>
    @endif

    <div class="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end dark:border-zinc-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Monitoreo PJUD</flux:badge>
            <flux:heading size="xl" level="1">Causas</flux:heading>
            <flux:text>
                Movimientos de las causas de SLAD detectados en el Estado Diario del Poder Judicial, agrupados por día.
                @unless ($this->puedeVerTodas)
                    Se muestran sólo las causas de las que eres responsable.
                @endunless
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" variant="primary" color="blue" icon="printer" x-on:click="const url = new URL(window.location.href); url.searchParams.set('imprimir', '1'); url.searchParams.delete('page'); window.open(url.toString(), '_blank');" wire:ignore>Imprimir</flux:button>
        </div>
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Buscar" placeholder="ROL, ingreso, caratulado o trámite" clearable />
            <flux:select wire:model.live="tribunal" label="Tribunal">
                <option value="">Todos los tribunales</option>
                @foreach ($this->tribunales as $item)
                    <option value="{{ $item['codigo'] }}">{{ $item['nombre'] }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="desde" label="Desde" type="date" />
            <flux:input wire:model.live="hasta" label="Hasta" type="date" />
            @if ($this->puedeVerTodas)
                <flux:select wire:model.live="responsable" label="Responsable">
                    <option value="">Todos</option>
                    <option value="sin_responsable">Sin responsable</option>
                    @foreach ($this->responsables as $usuario)
                        <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                    @endforeach
                </flux:select>
            @endif
        </div>
        <flux:button variant="ghost" icon="x-mark" wire:click="limpiarFiltros" class="w-fit">Limpiar filtros</flux:button>
    </flux:card>

    <flux:card>
        <flux:table :paginate="$this->movimientos">
            <flux:table.columns>
                <flux:table.column>Tribunal</flux:table.column>
                <flux:table.column>Causa</flux:table.column>
                <flux:table.column>Movimiento</flux:table.column>
                @if ($this->puedeVerTodas)
                    <flux:table.column>Responsable</flux:table.column>
                @endif
                <flux:table.column>Aviso</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @php($diaAnterior = null)
                @forelse ($this->movimientos as $movimiento)
                    @php($dia = $movimiento->fecha_estado_diario->toDateString())
                    @if ($dia !== $diaAnterior)
                        @php($diaAnterior = $dia)
                        <flux:table.row :key="'dia-'.$dia">
                            <flux:table.cell :colspan="$this->puedeVerTodas ? 5 : 4" class="bg-zinc-50 py-2 dark:bg-zinc-800/60">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ ucfirst($movimiento->fecha_estado_diario->translatedFormat('l j \d\e F \d\e Y')) }}</span>
                                    @if ($movimiento->fecha_estado_diario->isToday())
                                        <flux:badge color="blue" size="sm">Hoy</flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-500">{{ $this->movimientosPorDia[$dia] }} {{ $this->movimientosPorDia[$dia] === 1 ? 'movimiento' : 'movimientos' }}</span>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                    <flux:table.row :key="$movimiento->id">
                        <flux:table.cell class="min-w-36 whitespace-normal break-words">{{ $movimiento->tribunal_nombre }}</flux:table.cell>
                        <flux:table.cell class="min-w-48 whitespace-normal break-words">
                            @if ($movimiento->causa && ! $movimiento->causa->trashed() && auth()->user()->can('view', $movimiento->causa))
                                <a href="{{ route('causas.show', $movimiento->causa) }}" wire:navigate class="font-medium text-blue-700 hover:underline dark:text-blue-400">{{ $movimiento->causa->numero_causa ?? $movimiento->causa->nombre }}</a>
                            @else
                                <span class="font-medium">{{ $movimiento->causa?->numero_causa ?? $movimiento->causa?->nombre ?? '—' }}</span>
                            @endif
                            <br><span class="text-xs text-zinc-500">Ingreso PJUD: {{ $movimiento->numero_ingreso }}</span>
                            @if ($movimiento->caratulado)
                                <br><span class="text-xs text-zinc-500">{{ $movimiento->caratulado }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="min-w-64 whitespace-normal break-words">
                            <span>{{ $movimiento->descripcionTramite() ?? '—' }}</span>
                            @if ($movimiento->detalle)
                                <br><span class="text-xs text-zinc-500">{{ $movimiento->detalle }}</span>
                            @endif
                            @if ($movimiento->tipo_coincidencia !== 'exacta')
                                <br><flux:badge color="orange" size="sm" class="mt-1">Coincidencia {{ $movimiento->tipo_coincidencia }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        @if ($this->puedeVerTodas)
                            <flux:table.cell class="min-w-32 whitespace-normal break-words">{{ $movimiento->causa?->responsable?->name ?? 'Sin responsable' }}</flux:table.cell>
                        @endif
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($movimiento->notificado_at)
                                <flux:badge color="green" size="sm">Google Calendar</flux:badge>
                                <br><span class="text-xs text-zinc-500">{{ $movimiento->notificado_at->format('d/m H:i') }}</span>
                            @else
                                <flux:badge color="zinc" size="sm">Sin aviso</flux:badge>
                            @endif
                            @if ($this->puedeDescartarPjud)
                                <div class="mt-2">
                                    <flux:button size="xs" variant="ghost" icon="trash" class="text-red-600! dark:text-red-400!" wire:click="confirmarDescarte('movimiento', {{ $movimiento->id }})">Eliminar</flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell :colspan="$this->puedeVerTodas ? 5 : 4" class="py-10 text-center">No hay movimientos del Estado Diario para los filtros seleccionados.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <x-monitoreo.descartar-modal :registro="$this->registroADescartar" />

    @if ($imprimir)
        <section class="monitoreo-print-only" aria-label="Listado imprimible de movimientos del monitoreo">
            <header class="monitoreo-print-header">
                <img class="monitoreo-print-brand" src="{{ asset('images/logo-slad-impresion.png') }}" alt="SLAD — Sistema Logístico de Administración de Causas">
                <h1>Monitoreo PJUD — Movimientos del Estado Diario</h1>
                <div><strong>Fecha de impresión:</strong> {{ now()->format('d-m-Y H:i') }} · <strong>Impreso por:</strong> {{ auth()->user()?->name }}</div>
                <p class="monitoreo-print-filtros"><strong>Filtros:</strong> {{ $this->filtrosImpresion === [] ? 'Sin filtros (todos los movimientos visibles)' : implode(' · ', $this->filtrosImpresion) }}</p>
            </header>

            <table class="monitoreo-print-table">
                <thead>
                    <tr><th>Tribunal</th><th>Causa</th><th>Movimiento</th>@if ($this->puedeVerTodas)<th>Responsable</th>@endif<th>Aviso Google Calendar</th></tr>
                </thead>
                <tbody>
                    @php($diaImpresion = null)
                    @php($conteoDias = $this->movimientosImpresion->countBy(fn ($item) => $item->fecha_estado_diario->toDateString()))
                    @forelse ($this->movimientosImpresion as $fila)
                        @if ($fila->fecha_estado_diario->toDateString() !== $diaImpresion)
                            @php($diaImpresion = $fila->fecha_estado_diario->toDateString())
                            <tr class="monitoreo-print-dia"><td colspan="{{ $this->puedeVerTodas ? 5 : 4 }}">{{ ucfirst($fila->fecha_estado_diario->translatedFormat('l j \d\e F \d\e Y')) }} · {{ $conteoDias[$diaImpresion] }} movimiento(s)</td></tr>
                        @endif
                        <tr>
                            <td>{{ $fila->tribunal_nombre }}</td>
                            <td><strong>{{ $fila->causa?->numero_causa ?? $fila->causa?->nombre ?? '—' }}</strong><span>Ingreso PJUD: {{ $fila->numero_ingreso }}</span>@if ($fila->caratulado)<span>{{ $fila->caratulado }}</span>@endif</td>
                            <td>{{ $fila->descripcionTramite() ?? '—' }}@if ($fila->detalle)<span>{{ $fila->detalle }}</span>@endif @if ($fila->tipo_coincidencia !== 'exacta')<span>Coincidencia {{ $fila->tipo_coincidencia }}</span>@endif</td>
                            @if ($this->puedeVerTodas)<td>{{ $fila->causa?->responsable?->name ?? 'Sin responsable' }}</td>@endif
                            <td>{{ $fila->notificado_at ? 'Avisado '.$fila->notificado_at->format('d-m-Y H:i') : 'Sin aviso' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $this->puedeVerTodas ? 5 : 4 }}" class="monitoreo-print-empty">No hay movimientos para los filtros seleccionados.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <p class="monitoreo-print-total">Total de movimientos: {{ $this->movimientosImpresion->count() }}</p>
        </section>
    @endif

    <style>
    .monitoreo-print-only { display: none; }

    @media print {
        @page { size: 13in 8.5in; margin: 10mm; }

        [data-flux-sidebar], [data-flux-header], ui-sidebar, ui-sidebar-toggle, ui-header, ui-toast-group, nav { display: none !important; }
        .monitoreo-pagina > *:not(.monitoreo-print-only) { display: none !important; }
        html, body, .slad-main { min-height: 0 !important; height: auto !important; background: #fff !important; color: #111 !important; }
        .slad-main > [wire\:id] { flex: none !important; }
        .monitoreo-print-only { display: block !important; font-family: Arial, sans-serif; font-size: 8pt; line-height: 1.35; }
        .monitoreo-print-header { border-bottom: 2px solid #111; margin-bottom: 4mm; padding-bottom: 3mm; }
        .monitoreo-print-brand { display: block; height: 16mm; width: auto; margin: 0 0 1mm; }
        .monitoreo-print-header p:not(.monitoreo-print-brand) { margin: 0; }
        .monitoreo-print-header h1 { font-size: 13pt; margin: 3mm 0 2mm; text-transform: uppercase; }
        .monitoreo-print-filtros { margin: 1mm 0 0; color: #333; }
        .monitoreo-print-table { border-collapse: collapse; width: 100%; }
        .monitoreo-print-table th, .monitoreo-print-table td { border: 1px solid #aaa; padding: 1.5mm 2mm; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .monitoreo-print-table th { background: #eee; font-size: 7pt; text-transform: uppercase; }
        .monitoreo-print-table td span { display: block; margin-top: .5mm; color: #555; }
        .monitoreo-print-table tr { break-inside: avoid; page-break-inside: avoid; }
        .monitoreo-print-table tr.monitoreo-print-dia td { background: #f3f3f3; font-weight: 700; break-after: avoid; page-break-after: avoid; }
        .monitoreo-print-empty { padding: 8mm !important; text-align: center !important; }
        .monitoreo-print-total { font-size: 9pt; font-weight: 700; margin-top: 4mm; text-align: right; }
    }
    </style>
</div>
