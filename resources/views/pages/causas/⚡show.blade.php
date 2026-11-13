<?php

use App\Livewire\Monitoreo\ComponenteConDescartePjud;
use App\Models\Causa;
use App\Enums\Permiso;
use App\Enums\TipoMovimientoFinanciero;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\DocumentoCausa;
use App\Models\Recordatorio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;

new #[Title('Detalle de causa')] class extends ComponenteConDescartePjud
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
                    ->select(['id', 'causa_id', 'tipo', 'monto', 'fecha', 'observacion', 'created_by'])
                    ->with('creador:id,name')
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

    #[Computed]
    public function puedeVerMonitoreo(): bool
    {
        return (bool) auth()->user()?->can(Permiso::MonitoreoVer->value);
    }

    /**
     * Próxima vista de la causa en la tabla de una Corte, según el monitoreo
     * PJUD (se descartan las que fueron reemplazadas por una reprogramación).
     */
    #[Computed]
    public function proximaVistaPjud(): ?PjudProgramacion
    {
        if (! $this->puedeVerMonitoreo) {
            return null;
        }

        return PjudProgramacion::query()
            ->where('causa_id', $this->causaId)
            ->whereDate('fecha_vista', '>=', today())
            ->whereNotIn('id', PjudProgramacion::query()->select('id')->where('causa_id', $this->causaId)->reprogramadas())
            ->orderBy('fecha_vista')
            ->orderBy('numero_orden')
            ->first();
    }

    /** Último movimiento de la causa detectado en el Estado Diario del PJUD. */
    #[Computed]
    public function ultimoMovimientoPjud(): ?PjudMovimiento
    {
        if (! $this->puedeVerMonitoreo) {
            return null;
        }

        return PjudMovimiento::query()
            ->where('causa_id', $this->causaId)
            ->orderByDesc('fecha_estado_diario')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Agendamientos de la causa en salas de las Cortes, para la impresión:
     * todos, con las vistas reemplazadas por una reprogramación marcadas.
     *
     * @return array{programaciones: Collection<int, PjudProgramacion>, reprogramadas: list<int>}
     */
    #[Computed]
    public function salasImpresion(): array
    {
        if (! $this->puedeVerMonitoreo) {
            return ['programaciones' => new Collection, 'reprogramadas' => []];
        }

        return [
            'programaciones' => PjudProgramacion::query()
                ->where('causa_id', $this->causaId)
                ->orderByDesc('fecha_vista')
                ->orderByDesc('id')
                ->get(),
            'reprogramadas' => PjudProgramacion::query()
                ->where('causa_id', $this->causaId)
                ->reprogramadas()
                ->pluck('id')
                ->all(),
        ];
    }

    /** @return Collection<int, PjudMovimiento> */
    #[Computed]
    public function movimientosPjudImpresion(): Collection
    {
        if (! $this->puedeVerMonitoreo) {
            return new Collection;
        }

        return PjudMovimiento::query()
            ->where('causa_id', $this->causaId)
            ->orderByDesc('fecha_estado_diario')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, DocumentoCausa> */
    #[Computed]
    public function documentosImpresion(): Collection
    {
        if (! auth()->user()?->can(Permiso::DocumentosVer->value)) {
            return new Collection;
        }

        return DocumentoCausa::query()
            ->with('usuario:id,name')
            ->where('causa_id', $this->causaId)
            ->latest()
            ->get();
    }

    /** @return Collection<int, Recordatorio> */
    #[Computed]
    public function recordatoriosImpresion(): Collection
    {
        if (! auth()->user()?->can('viewAny', Recordatorio::class)) {
            return new Collection;
        }

        return Recordatorio::query()
            ->with(['usuario:id,name', 'creador:id,name'])
            ->where('causa_id', $this->causaId)
            ->orderBy('fecha_hora')
            ->orderBy('id')
            ->get();
    }

    /**
     * En el detalle sólo se descartan registros de esta causa (la causa ya
     * está autorizada en mount).
     *
     * @param  Builder<PjudMovimiento>|Builder<PjudProgramacion>  $query
     * @return Builder<PjudMovimiento>|Builder<PjudProgramacion>
     */
    protected function limitarRegistrosDescartables(Builder $query): Builder
    {
        return $query->where('causa_id', $this->causaId);
    }

    protected function despuesDeDescartar(): void
    {
        unset($this->proximaVistaPjud, $this->ultimoMovimientoPjud, $this->salasImpresion, $this->movimientosPjudImpresion);
    }

    #[On('actuacion-saved')]
    public function refreshCausa(): void
    {
        unset($this->causa);
        unset($this->resumenFinancieroImpresion);
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="screen-only grid grid-cols-1 gap-6">
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

        @if ($this->puedeVerMonitoreo)
            <div class="grid gap-3 rounded-lg border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:badge color="blue" size="sm">Monitoreo PJUD</flux:badge>
                    <span class="text-xs text-zinc-500">Información automática obtenida del Poder Judicial</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-1 text-sm">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Próxima vista en sala</span>
                        @if ($this->proximaVistaPjud)
                            @php($vista = $this->proximaVistaPjud)
                            <span class="font-semibold">{{ ucfirst($vista->fecha_vista->translatedFormat('l j \d\e F \d\e Y')) }}</span>
                            <span class="whitespace-normal break-words">{{ $vista->tribunal_nombre }} · {{ $vista->sala }} · Tabla {{ $vista->tipo_tabla }}@if ($vista->numero_orden) · N° orden {{ $vista->numero_orden }}@endif</span>
                            @if ($vista->relator)
                                <span class="text-zinc-500">Relator: {{ $vista->relator }}</span>
                            @endif
                            <span class="text-xs text-zinc-500">Detectada el {{ $vista->detectada_en?->format('d/m/Y H:i') ?? '—' }} · Ingreso PJUD: {{ $vista->numero_ingreso }}</span>
                            <div class="flex flex-wrap items-center gap-3">
                                <a href="{{ route('monitoreo.salas', ['search' => $vista->numero_ingreso]) }}" wire:navigate class="w-fit text-xs font-medium text-blue-700 hover:underline dark:text-blue-400">Ver historial de salas</a>
                                @if ($this->puedeDescartarPjud)
                                    <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" wire:click="confirmarDescarte('programacion', {{ $vista->id }})">Eliminar</button>
                                @endif
                            </div>
                        @else
                            <span class="text-zinc-500">Sin vistas programadas detectadas.</span>
                        @endif
                    </div>

                    <div class="grid content-start gap-1 text-sm">
                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Último movimiento PJUD</span>
                        @if ($this->ultimoMovimientoPjud)
                            @php($movimiento = $this->ultimoMovimientoPjud)
                            <span class="font-semibold">{{ ucfirst($movimiento->fecha_estado_diario->translatedFormat('l j \d\e F \d\e Y')) }}</span>
                            <span class="whitespace-normal break-words">{{ $movimiento->tribunal_nombre }}</span>
                            @if ($movimiento->descripcionTramite())
                                <span>{{ $movimiento->descripcionTramite() }}</span>
                            @endif
                            @if ($movimiento->detalle)
                                <span class="whitespace-normal break-words text-zinc-500">{{ $movimiento->detalle }}</span>
                            @endif
                            <span class="text-xs text-zinc-500">Detectado el {{ $movimiento->created_at?->format('d/m/Y H:i') ?? '—' }} · Ingreso PJUD: {{ $movimiento->numero_ingreso }}</span>
                            <div class="flex flex-wrap items-center gap-3">
                                <a href="{{ route('monitoreo.causas', ['search' => $movimiento->numero_ingreso]) }}" wire:navigate class="w-fit text-xs font-medium text-blue-700 hover:underline dark:text-blue-400">Ver todos los movimientos</a>
                                @if ($this->puedeDescartarPjud)
                                    <button type="button" class="text-xs font-medium text-red-600 hover:underline dark:text-red-400" wire:click="confirmarDescarte('movimiento', {{ $movimiento->id }})">Eliminar</button>
                                @endif
                            </div>
                        @else
                            <span class="text-zinc-500">Sin movimientos detectados en el Estado Diario.</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if (filled($this->causa->observacion_importante))
            <div class="grid gap-1">
                @if ($this->puedeVerMonitoreo)
                    <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Observación registrada</span>
                @endif
                <p class="whitespace-pre-line text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $this->causa->observacion_importante }}</p>
            </div>
        @elseif (! $this->puedeVerMonitoreo)
            <p class="text-sm leading-6 text-zinc-700 dark:text-zinc-300">Sin observaciones importantes.</p>
        @endif
    </flux:card>

    <x-monitoreo.descartar-modal :registro="$this->registroADescartar" />

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

        @if (filled($this->causa->observacion_importante))
            <section class="print-section">
                <h2>Observación importante</h2>
                <p class="print-observation">{{ $this->causa->observacion_importante }}</p>
            </section>
        @endif

        @if ($this->puedeVerMonitoreo)
            <section class="print-section">
                <h2>Monitoreo Poder Judicial (PJUD)</h2>
                <dl class="print-details">
                    <div class="print-detail">
                        <dt>Próxima vista en sala</dt>
                        <dd>
                            @if ($this->proximaVistaPjud)
                                {{ $this->proximaVistaPjud->fecha_vista->format('d-m-Y') }} · {{ $this->proximaVistaPjud->tribunal_nombre }} · {{ $this->proximaVistaPjud->sala }}@if ($this->proximaVistaPjud->numero_orden) · N° orden {{ $this->proximaVistaPjud->numero_orden }}@endif
                            @else
                                Sin vistas programadas detectadas.
                            @endif
                        </dd>
                    </div>
                    <div class="print-detail">
                        <dt>Último movimiento PJUD</dt>
                        <dd>
                            @if ($this->ultimoMovimientoPjud)
                                {{ $this->ultimoMovimientoPjud->fecha_estado_diario->format('d-m-Y') }} · {{ $this->ultimoMovimientoPjud->tribunal_nombre }}@if ($this->ultimoMovimientoPjud->descripcionTramite()) · {{ $this->ultimoMovimientoPjud->descripcionTramite() }}@endif
                            @else
                                Sin movimientos detectados.
                            @endif
                        </dd>
                    </div>
                </dl>

                <h3>Agendamientos en salas</h3>
                @if ($this->salasImpresion['programaciones']->isNotEmpty())
                    <table class="print-table">
                        <thead><tr><th>Fecha vista</th><th>Corte / sala</th><th>Tabla · N° orden</th><th>Relator</th><th>Estado</th><th>Detectada</th></tr></thead>
                        <tbody>
                            @foreach ($this->salasImpresion['programaciones'] as $vista)
                                <tr>
                                    <td>{{ $vista->fecha_vista->format('d-m-Y') }}</td>
                                    <td>{{ $vista->tribunal_nombre }} · {{ $vista->sala }}</td>
                                    <td>{{ $vista->tipo_tabla }}{{ $vista->numero_orden ? ' · '.$vista->numero_orden : '' }}</td>
                                    <td>{{ $vista->relator ?? '—' }}</td>
                                    <td>{{ in_array($vista->id, $this->salasImpresion['reprogramadas'], true) ? 'Reprogramada' : ($vista->fecha_vista->isBefore(today()) ? 'Fecha pasada' : 'Próxima') }}</td>
                                    <td>{{ $vista->detectada_en?->format('d-m-Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="print-empty">Sin agendamientos en salas detectados.</p>
                @endif

                <h3>Movimientos en el Estado Diario</h3>
                @forelse ($this->movimientosPjudImpresion as $movimientoPjud)
                    <article class="print-entry">
                        <div class="print-entry-meta"><strong>{{ $movimientoPjud->fecha_estado_diario->format('d-m-Y') }}</strong><span>{{ $movimientoPjud->tribunal_nombre }}</span></div>
                        @if ($movimientoPjud->descripcionTramite())<p>{{ $movimientoPjud->descripcionTramite() }}</p>@endif
                        @if ($movimientoPjud->detalle)<p>{{ $movimientoPjud->detalle }}</p>@endif
                        <small>Ingreso PJUD: {{ $movimientoPjud->numero_ingreso }} · Detectado: {{ $movimientoPjud->created_at?->format('d-m-Y H:i') ?? '—' }} · {{ $movimientoPjud->notificado_at ? 'Avisado en Google Calendar' : 'Sin aviso en Google Calendar' }}</small>
                    </article>
                @empty
                    <p class="print-empty">Sin movimientos detectados en el Estado Diario.</p>
                @endforelse
            </section>
        @endif

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
                    <thead><tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th>Observación</th><th>Registrado por</th></tr></thead>
                    <tbody>
                        @foreach ($this->causa->movimientosFinancieros as $movimiento)
                            <tr>
                                <td>{{ $movimiento->fecha?->format('d-m-Y') ?? '—' }}</td>
                                <td>{{ $movimiento->tipo->value }}</td>
                                <td class="print-amount">{{ '$'.number_format((float) $movimiento->monto, 0, ',', '.') }}</td>
                                <td>{{ $movimiento->observacion ?? '—' }}</td>
                                <td>{{ $movimiento->creador?->name ?? '—' }}</td>
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

        @if (auth()->user()?->can(Permiso::DocumentosVer->value))
            <section class="print-section">
                <h2>Documentos adjuntos</h2>
                @if ($this->documentosImpresion->isNotEmpty())
                    <table class="print-table">
                        <thead><tr><th>Documento</th><th>Descripción</th><th>Fecha</th><th>Subido por</th></tr></thead>
                        <tbody>
                            @foreach ($this->documentosImpresion as $documento)
                                <tr>
                                    <td>{{ $documento->nombre_original }}</td>
                                    <td>{{ $documento->descripcion ?? '—' }}</td>
                                    <td>{{ $documento->created_at?->format('d-m-Y H:i') ?? '—' }}</td>
                                    <td>{{ $documento->usuario?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="print-empty">No hay documentos adjuntos.</p>
                @endif
            </section>
        @endif

        @can('viewAny', \App\Models\Recordatorio::class)
            <section class="print-section">
                <h2>Recordatorios</h2>
                @forelse ($this->recordatoriosImpresion as $recordatorio)
                    <article class="print-entry">
                        <div class="print-entry-meta"><strong>{{ $recordatorio->fecha_hora?->format('d-m-Y H:i') ?? '—' }}</strong><span>{{ $recordatorio->titulo }}</span><span>{{ ucfirst(mb_strtolower($recordatorio->estado->value)) }}</span></div>
                        @if ($recordatorio->descripcion)<p>{{ $recordatorio->descripcion }}</p>@endif
                        <small>Asignado a: {{ $recordatorio->usuario?->name ?? '—' }} · Creado por: {{ $recordatorio->creador?->name ?? '—' }} · {{ $recordatorio->created_at?->format('d-m-Y H:i') ?? '—' }}</small>
                    </article>
                @empty
                    <p class="print-empty">No hay recordatorios registrados.</p>
                @endforelse
            </section>
        @endcan
    </section>

    <style>
    .print-only { display: none; }

    @media print {
        @page { size: 8.5in 13in; margin: 12mm; }

        [data-flux-sidebar], [data-flux-header], ui-sidebar, ui-sidebar-toggle, ui-header, ui-toast-group, nav, .screen-only { display: none !important; }
        html, body, .slad-main { min-height: 0 !important; height: auto !important; background: #fff !important; color: #111 !important; }
        .slad-main > [wire\:id] { flex: none !important; }
        .print-only { display: block !important; }
        .printable-cause { font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.45; }
        .print-header { border-bottom: 2px solid #111; margin-bottom: 7mm; padding-bottom: 4mm; }
        .print-brand { font-size: 18pt; font-weight: 700; letter-spacing: .08em; margin: 0; }
        .print-header p:not(.print-brand) { margin: 0; }
        .print-header h1 { font-size: 15pt; margin: 5mm 0 3mm; text-transform: uppercase; }
        .print-header-meta { display: flex; justify-content: space-between; gap: 6mm; font-size: 9pt; }
        .print-entry, .print-detail, .print-totals { break-inside: avoid; page-break-inside: avoid; }
        .print-section h2, .print-section h3 { break-after: avoid; page-break-after: avoid; }
        .print-section h3 { font-size: 10.5pt; margin: 5mm 0 2mm; }
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
