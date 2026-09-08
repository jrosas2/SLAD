<?php

use App\Models\Causa;
use App\Enums\TipoMovimientoFinanciero;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de causa')] class extends Component
{
    #[Locked]
    public int $causaId;

    public function mount(Causa $causa): void
    {
        Gate::authorize('view', $causa);
        $this->causaId = $causa->id;
    }

    #[Computed]
    public function causa(): Causa
    {
        return Causa::query()
            ->with([
                'juzgado:id,ciudad_id,nombre',
                'juzgado.ciudad:id,nombre',
                'materia:id,nombre',
                'submateria:id,nombre',
                'accion:id,nombre',
                'estadoProcesal:id,nombre',
                'estadoCausa:id,nombre',
                'direccion:id,nombre',
                'responsable:id,codigo,name',
                'actuaciones' => fn (HasMany $query) => $query
                    ->select(['id', 'causa_id', 'fecha', 'estado_procesal_id', 'descripcion', 'created_by'])
                    ->with(['estadoProcesal:id,nombre', 'creador:id,name'])
                    ->reorder()
                    ->orderBy('fecha')
                    ->orderBy('id'),
                'movimientosFinancieros' => fn (HasMany $query) => $query
                    ->select(['id', 'causa_id', 'tipo', 'monto', 'fecha', 'observacion'])
                    ->reorder()
                    ->orderBy('fecha')
                    ->orderBy('id'),
            ])
            ->findOrFail($this->causaId);
    }

    /** @return array{totalIngresos: int, totalEgresos: int, saldo: int} */
    #[Computed]
    public function resumenFinancieroImpresion(): array
    {
        $movimientos = $this->causa->movimientosFinancieros;
        $totalIngresos = (int) $movimientos
            ->where('tipo', TipoMovimientoFinanciero::Ingreso)
            ->sum('monto');
        $totalEgresos = (int) $movimientos
            ->where('tipo', TipoMovimientoFinanciero::Egreso)
            ->sum('monto');

        return [
            'totalIngresos' => $totalIngresos,
            'totalEgresos' => $totalEgresos,
            'saldo' => $totalIngresos - $totalEgresos,
        ];
    }

    #[On('actuacion-saved')]
    public function refreshCausa(): void
    {
        unset($this->causa);
        unset($this->resumenFinancieroImpresion);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="screen-only grid gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Resumen del expediente</flux:badge>
            <flux:heading size="xl" level="1">{{ $this->causa->nombre }}</flux:heading>
            <flux:text>{{ $this->causa->numero_causa ?? 'Sin número de causa' }}</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('causas.index')" wire:navigate>Volver</flux:button>
            @can('update', $this->causa)
                <flux:button variant="primary" icon="pencil-square" :href="route('causas.edit', $this->causa)" wire:navigate>Editar causa</flux:button>
            @endcan
            @can('view', $this->causa)
                <flux:button type="button" variant="primary" color="blue" x-on:click="window.print()" wire:ignore>Imprimir</flux:button>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" heading="{{ session('status') }}" />
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card class="grid gap-5">
            <flux:heading size="lg">Identificación</flux:heading>
            <dl class="grid gap-4">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Nombre</dt><dd class="mt-1">{{ $this->causa->nombre }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Número</dt><dd class="mt-1">{{ $this->causa->numero_causa ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Fecha de causa</dt><dd class="mt-1">{{ $this->causa->fecha_causa?->format('d-m-Y') ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Fecha de ingreso</dt><dd class="mt-1">{{ $this->causa->fecha_ingreso?->format('d-m-Y') ?? '—' }}</dd></div>
            </dl>
        </flux:card>

        <flux:card class="grid gap-5">
            <flux:heading size="lg">Tribunal</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-3">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Ciudad</dt><dd class="mt-1">{{ $this->causa->juzgado?->ciudad?->nombre ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Juzgado</dt><dd class="mt-1">{{ $this->causa->juzgado?->nombre ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Dirección</dt><dd class="mt-1">{{ $this->causa->direccion?->nombre ?? '—' }}</dd></div>
            </dl>
        </flux:card>

        <flux:card class="grid gap-5">
            <flux:heading size="lg">Clasificación</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Materia</dt><dd class="mt-1">{{ $this->causa->materia->nombre }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Submateria</dt><dd class="mt-1">{{ $this->causa->submateria?->nombre ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Acción</dt><dd class="mt-1">{{ $this->causa->accion?->nombre ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Demandante / demandado</dt><dd class="mt-1">{{ $this->causa->demandante_demandado ?? '—' }}</dd></div>
            </dl>
        </flux:card>

        <flux:card class="grid gap-5">
            <flux:heading size="lg">Estado</flux:heading>
            <div class="flex flex-wrap gap-3">
                <div class="grid gap-1"><span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Estado procesal</span><flux:badge color="blue">{{ $this->causa->estadoProcesal?->nombre ?? 'Sin estado' }}</flux:badge></div>
                <div class="grid gap-1"><span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Estado de causa</span><flux:badge color="zinc">{{ $this->causa->estadoCausa?->nombre ?? 'Sin estado' }}</flux:badge></div>
            </div>
        </flux:card>

        <flux:card class="grid gap-5">
            <flux:heading size="lg">Responsable</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Responsable</dt>
                    <dd class="mt-1">{{ $this->causa->responsable?->etiquetaResponsable() ?? '—' }}</dd>
                </div>
            </dl>
        </flux:card>

        <flux:card class="grid gap-5">
            <flux:heading size="lg">Información económica</flux:heading>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Monto demandado</dt><dd class="mt-1 text-lg font-semibold">{{ $this->causa->monto_demandado === null ? '—' : '$ '.number_format((float) $this->causa->monto_demandado, 0, ',', '.') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Cotizaciones</dt><dd class="mt-1"><flux:badge :color="$this->causa->tiene_cotizaciones ? 'green' : 'zinc'">{{ $this->causa->tiene_cotizaciones ? 'Sí' : 'No' }}</flux:badge></dd></div>
            </dl>
        </flux:card>
    </div>

    <flux:card class="grid gap-4">
        <flux:heading size="lg">Observaciones importantes</flux:heading>
        <p class="whitespace-pre-line text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $this->causa->observacion_importante ?? 'Sin observaciones importantes.' }}</p>
    </flux:card>

    <flux:card>
        <livewire:causas.actuaciones :causa="$this->causa" :key="'actuaciones-'.$causaId" />
    </flux:card>

    <flux:card>
        <livewire:causas.movimientos-financieros :causa="$this->causa" :key="'movimientos-financieros-'.$causaId" />
    </flux:card>

    @if (auth()->user()?->can(\App\Enums\Permiso::DocumentosVer->value))
        <flux:card>
            <livewire:causas.documentos :causa="$this->causa" :key="'documentos-'.$causaId" />
        </flux:card>
    @endif

    @can('viewAny', \App\Models\Recordatorio::class)
        <flux:card>
            <livewire:causas.recordatorios :causa="$this->causa" :key="'recordatorios-'.$causaId" />
        </flux:card>
    @endcan
    </div>

    <section class="print-only printable-cause" aria-label="Detalle imprimible de causa">
        <header class="print-header">
            <p class="print-brand">SLAD</p>
            <p>Sistema Logístico de Administración de Derecho</p>
            <h1>Detalle de causa</h1>
            <div class="print-header-meta">
                <span><strong>N.º de causa:</strong> {{ $this->causa->numero_causa ?? '—' }}</span>
                <span><strong>Fecha de impresión:</strong> {{ now()->format('d-m-Y H:i') }}</span>
            </div>
        </header>

        <section class="print-section">
            <h2>Datos generales</h2>
            <dl class="print-details">
                <div class="print-detail wide"><dt>Nombre de la causa</dt><dd>{{ $this->causa->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Número de causa</dt><dd>{{ $this->causa->numero_causa ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Fecha de causa</dt><dd>{{ $this->causa->fecha_causa?->format('d-m-Y') ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Fecha de ingreso</dt><dd>{{ $this->causa->fecha_ingreso?->format('d-m-Y') ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Materia</dt><dd>{{ $this->causa->materia?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Submateria</dt><dd>{{ $this->causa->submateria?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Ciudad</dt><dd>{{ $this->causa->juzgado?->ciudad?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Juzgado</dt><dd>{{ $this->causa->juzgado?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Acción</dt><dd>{{ $this->causa->accion?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Dirección</dt><dd>{{ $this->causa->direccion?->nombre ?? '—' }}</dd></div>
                <div class="print-detail wide"><dt>Demandante / demandado</dt><dd>{{ $this->causa->demandante_demandado ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Estado procesal</dt><dd>{{ $this->causa->estadoProcesal?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Estado de causa</dt><dd>{{ $this->causa->estadoCausa?->nombre ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Responsable</dt><dd>{{ $this->causa->responsable?->etiquetaResponsable() ?? '—' }}</dd></div>
                <div class="print-detail"><dt>Monto demandado</dt><dd>{{ $this->causa->monto_demandado === null ? '—' : '$'.number_format((float) $this->causa->monto_demandado, 0, ',', '.') }}</dd></div>
                <div class="print-detail"><dt>Estado de cotizaciones</dt><dd>{{ $this->causa->tiene_cotizaciones ? 'Sí' : 'No' }}</dd></div>
            </dl>
        </section>

        <section class="print-section">
            <h2>Observación importante</h2>
            <p class="print-observation">{{ $this->causa->observacion_importante ?? '—' }}</p>
        </section>

        <section class="print-section">
            <h2>Actuaciones</h2>
            @forelse ($this->causa->actuaciones as $actuacion)
                <article class="print-entry">
                    <div class="print-entry-meta"><strong>{{ $actuacion->fecha?->format('d-m-Y') ?? '—' }}</strong><span>{{ $actuacion->estadoProcesal?->nombre ?? '—' }}</span></div>
                    <p>{{ $actuacion->descripcion }}</p>
                    <small>Registrado por: {{ $actuacion->creador?->name ?? '—' }}</small>
                </article>
            @empty
                <p class="print-empty">No hay actuaciones registradas.</p>
            @endforelse
        </section>

        <section class="print-section">
            <h2>Movimientos financieros</h2>
            @if ($this->causa->movimientosFinancieros->isNotEmpty())
                <table class="print-table">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Observación</th></tr></thead>
                    <tbody>
                        @foreach ($this->causa->movimientosFinancieros as $movimiento)
                            <tr>
                                <td>{{ $movimiento->fecha?->format('d-m-Y') ?? '—' }}</td>
                                <td>{{ $movimiento->tipo->value }}</td>
                                <td class="print-amount">{{ '$'.number_format((float) $movimiento->monto, 0, ',', '.') }}</td>
                                <td>{{ $movimiento->observacion ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <dl class="print-totals">
                    <div><dt>Total ingresos</dt><dd>{{ '$'.number_format($this->resumenFinancieroImpresion['totalIngresos'], 0, ',', '.') }}</dd></div>
                    <div><dt>Total egresos</dt><dd>{{ '$'.number_format($this->resumenFinancieroImpresion['totalEgresos'], 0, ',', '.') }}</dd></div>
                    <div><dt>Saldo</dt><dd>{{ '$'.number_format($this->resumenFinancieroImpresion['saldo'], 0, ',', '.') }}</dd></div>
                </dl>
            @else
                <p class="print-empty">No hay movimientos financieros registrados.</p>
            @endif
        </section>
    </section>

    <style>
    .print-only { display: none; }

    @media print {
        @page { size: 8.5in 13in; margin: 12mm; }

        [data-flux-sidebar], ui-sidebar, ui-sidebar-toggle, ui-header, nav, .screen-only { display: none !important; }
        html, body, .slad-main { min-height: 0 !important; height: auto !important; background: #fff !important; color: #111 !important; }
        .slad-main > [wire\:id] { flex: none !important; }
        .print-only { display: block !important; }
        .printable-cause { font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.45; }
        .print-header { border-bottom: 2px solid #111; margin-bottom: 7mm; padding-bottom: 4mm; }
        .print-brand { font-size: 18pt; font-weight: 700; letter-spacing: .08em; margin: 0; }
        .print-header p:not(.print-brand) { margin: 0; }
        .print-header h1 { font-size: 15pt; margin: 5mm 0 3mm; text-transform: uppercase; }
        .print-header-meta { display: flex; justify-content: space-between; gap: 6mm; font-size: 9pt; }
        .print-section, .print-entry, .print-detail, .print-totals { break-inside: avoid; page-break-inside: avoid; }
        .print-section { margin-top: 6mm; }
        .print-section h2 { border-bottom: 1px solid #777; font-size: 12pt; margin: 0 0 3mm; padding-bottom: 1.5mm; }
        .print-details { display: grid; gap: 3mm 7mm; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
        .print-detail.wide { grid-column: 1 / -1; }
        .print-detail dt, .print-totals dt { color: #555; font-size: 8pt; font-weight: 700; text-transform: uppercase; }
        .print-detail dd, .print-totals dd { margin: .5mm 0 0; overflow-wrap: anywhere; }
        .print-observation, .print-entry p { margin: 0; white-space: pre-wrap; }
        .print-entry { border-bottom: 1px solid #ccc; padding: 3mm 0; }
        .print-entry-meta { display: flex; gap: 5mm; }
        .print-entry small { color: #555; display: block; margin-top: 2mm; }
        .print-table { border-collapse: collapse; font-size: 9pt; width: 100%; }
        .print-table th, .print-table td { border: 1px solid #aaa; padding: 2mm; text-align: left; vertical-align: top; }
        .print-table th { background: #eee; font-weight: 700; }
        .print-table tr { break-inside: avoid; page-break-inside: avoid; }
        .print-amount { text-align: right !important; white-space: nowrap; }
        .print-totals { display: grid; gap: 2mm; grid-template-columns: repeat(3, 1fr); margin: 4mm 0 0; }
        .print-totals div { border-top: 1px solid #777; padding-top: 1mm; }
        .print-totals dd { font-weight: 700; }
        .print-empty { color: #555; margin: 0; }
    }
    </style>
</div>
