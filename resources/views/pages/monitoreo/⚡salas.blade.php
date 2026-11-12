<?php

use App\Enums\Permiso;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Monitoreo de salas')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $corte = '';

    #[Url(except: '')]
    public string $sala = '';

    #[Url(except: '')]
    public string $responsable = '';

    #[Url(except: '')]
    public string $desde = '';

    #[Url(except: '')]
    public string $hasta = '';

    /** proximas | realizadas | reprogramadas | '' (todas) */
    #[Url(except: '')]
    public string $estado = '';

    #[Locked]
    public ?int $historialId = null;

    public bool $showHistorial = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permiso::MonitoreoVer->value), 403);
    }

    public function updated(string $property): void
    {
        if ($property === 'corte') {
            $this->sala = '';
        }

        if (in_array($property, ['search', 'corte', 'sala', 'responsable', 'desde', 'hasta', 'estado'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function puedeVerTodas(): bool
    {
        return (bool) auth()->user()?->can(Permiso::MonitoreoVerTodas->value);
    }

    /** @return Builder<PjudProgramacion> */
    private function visibles(): Builder
    {
        return PjudProgramacion::query()->visibleFor(auth()->user());
    }

    /** @return LengthAwarePaginator<PjudProgramacion> */
    #[Computed]
    public function programaciones(): LengthAwarePaginator
    {
        $hoy = now()->toDateString();

        return $this->visibles()
            ->with(['causa:id,nombre,numero_causa,responsable_id,deleted_at', 'causa.responsable:id,codigo,name'])
            ->when($this->corte !== '', fn (Builder $query) => $query->where('tribunal_pjud_codigo', $this->corte))
            ->when($this->sala !== '', fn (Builder $query) => $query->where('sala_numero', $this->sala))
            ->when($this->desde !== '', fn (Builder $query) => $query->whereDate('fecha_vista', '>=', $this->desde))
            ->when($this->hasta !== '', fn (Builder $query) => $query->whereDate('fecha_vista', '<=', $this->hasta))
            ->when($this->estado === 'proximas', fn (Builder $query) => $query->whereDate('fecha_vista', '>=', $hoy))
            ->when($this->estado === 'realizadas', fn (Builder $query) => $query->whereDate('fecha_vista', '<', $hoy))
            ->when($this->estado === 'reprogramadas', fn (Builder $query) => $query->reprogramadas())
            ->when($this->puedeVerTodas && $this->responsable === 'sin_responsable', fn (Builder $query) => $query->whereHas('causa', fn (Builder $causa) => $causa->whereNull('responsable_id')))
            ->when($this->puedeVerTodas && $this->responsable !== '' && $this->responsable !== 'sin_responsable', fn (Builder $query) => $query->whereHas('causa', fn (Builder $causa) => $causa->where('responsable_id', $this->responsable)))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';
                $query->where(fn (Builder $busqueda) => $busqueda
                    ->where('numero_ingreso', 'like', $search)
                    ->orWhere('caratulado', 'like', $search)
                    ->orWhere('relator', 'like', $search)
                    ->orWhereHas('causa', fn (Builder $causa) => $causa
                        ->where('numero_causa', 'like', $search)
                        ->orWhere('nombre', 'like', $search)));
            })
            ->orderByDesc('fecha_vista')
            ->orderBy('tribunal_nombre')
            ->orderBy('sala_numero')
            ->orderBy('numero_orden')
            ->paginate(20);
    }

    /**
     * Cantidad de causas por día de vista en la página actual (encabezados de grupo).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function causasPorDia(): array
    {
        return $this->programaciones->getCollection()
            ->countBy(fn (PjudProgramacion $programacion) => $programacion->fecha_vista->toDateString())
            ->all();
    }

    /**
     * IDs de la página actual que fueron reemplazados por una reprogramación.
     *
     * @return list<int>
     */
    #[Computed]
    public function reprogramadasEnPagina(): array
    {
        $ids = $this->programaciones->getCollection()->modelKeys();

        return $ids === [] ? [] : PjudProgramacion::query()->whereKey($ids)->reprogramadas()->pluck('id')->all();
    }

    /** @return list<array{codigo: int, nombre: string}> */
    #[Computed]
    public function cortes(): array
    {
        return $this->visibles()
            ->select(['tribunal_pjud_codigo', 'tribunal_nombre'])
            ->distinct()
            ->orderBy('tribunal_nombre')
            ->get()
            ->unique('tribunal_pjud_codigo')
            ->map(fn (PjudProgramacion $programacion) => ['codigo' => $programacion->tribunal_pjud_codigo, 'nombre' => $programacion->tribunal_nombre])
            ->values()
            ->all();
    }

    /** @return list<array{numero: string, nombre: string}> */
    #[Computed]
    public function salas(): array
    {
        return $this->visibles()
            ->select(['sala_numero', 'sala'])
            ->when($this->corte !== '', fn (Builder $query) => $query->where('tribunal_pjud_codigo', $this->corte))
            ->distinct()
            ->get()
            ->unique('sala_numero')
            ->sortBy(fn (PjudProgramacion $programacion) => str_pad($programacion->sala_numero, 4, '0', STR_PAD_LEFT))
            ->map(fn (PjudProgramacion $programacion) => ['numero' => $programacion->sala_numero, 'nombre' => $programacion->sala])
            ->values()
            ->all();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function responsables(): Collection
    {
        return User::query()
            ->select(['id', 'codigo', 'name'])
            ->whereHas('causasAsignadas', fn (Builder $causa) => $causa->whereHas('pjudProgramaciones'))
            ->orderBy('name')
            ->get();
    }

    /** Muestra la tabla completa de una sala en un día (sólo causas de SLAD visibles). */
    public function verSala(int $id): void
    {
        $programacion = $this->visibles()->findOrFail($id);

        $this->corte = (string) $programacion->tribunal_pjud_codigo;
        $this->sala = $programacion->sala_numero;
        $this->desde = $programacion->fecha_vista->toDateString();
        $this->hasta = $programacion->fecha_vista->toDateString();
        $this->estado = '';
        $this->search = '';
        $this->resetPage();
    }

    public function verHistorial(int $id): void
    {
        $this->historialId = $this->visibles()->whereKey($id)->value('id');
        abort_if($this->historialId === null, 404);
        $this->showHistorial = true;
    }

    #[Computed]
    public function seleccionada(): ?PjudProgramacion
    {
        return $this->historialId === null ? null : $this->visibles()
            ->with(['causa:id,nombre,numero_causa,responsable_id,deleted_at', 'causa.responsable:id,codigo,name'])
            ->find($this->historialId);
    }

    /**
     * Todas las apariciones de la causa en tablas de salas, de la más antigua a
     * la más reciente, con lo que cambió respecto de la anterior en esa Corte.
     *
     * @return list<array{programacion: PjudProgramacion, cambios: list<string>}>
     */
    #[Computed]
    public function historial(): array
    {
        if ($this->seleccionada === null) {
            return [];
        }

        $anteriores = [];
        $filas = [];

        $programaciones = $this->visibles()
            ->where('causa_id', $this->seleccionada->causa_id)
            ->orderBy('detectada_en')
            ->orderBy('id')
            ->get();

        foreach ($programaciones as $programacion) {
            $clave = $programacion->tribunal_pjud_codigo.'|'.$programacion->tipo_tabla_codigo;
            $anterior = $anteriores[$clave] ?? null;
            $cambios = [];

            if ($anterior !== null && $anterior->fecha_vista->gte($programacion->detectada_en?->startOfDay() ?? $programacion->fecha_vista)) {
                if (! $anterior->fecha_vista->equalTo($programacion->fecha_vista)) {
                    $cambios[] = 'Fecha: '.$anterior->fecha_vista->format('d/m/Y').' → '.$programacion->fecha_vista->format('d/m/Y');
                }

                if ($anterior->sala_numero !== $programacion->sala_numero) {
                    $cambios[] = 'Sala: '.$anterior->sala.' → '.$programacion->sala;
                }
            }

            $filas[] = ['programacion' => $programacion, 'cambios' => $cambios];
            $anteriores[$clave] = $programacion;
        }

        return array_reverse($filas);
    }

    /** @return Collection<int, PjudMovimiento> */
    #[Computed]
    public function movimientos(): Collection
    {
        if ($this->seleccionada === null) {
            return new Collection;
        }

        return PjudMovimiento::query()
            ->where('causa_id', $this->seleccionada->causa_id)
            ->orderByDesc('fecha_estado_diario')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
    }

    public function limpiarFiltros(): void
    {
        $this->reset('search', 'corte', 'sala', 'responsable', 'desde', 'hasta', 'estado');
        $this->resetPage();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end dark:border-zinc-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Monitoreo PJUD</flux:badge>
            <flux:heading size="xl" level="1">Salas</flux:heading>
            <flux:text>
                Causas de SLAD detectadas en las tablas de las Cortes, por sala y fecha de vista, con sus reprogramaciones.
                @unless ($this->puedeVerTodas)
                    Se muestran sólo las causas de las que eres responsable.
                @endunless
            </flux:text>
        </div>
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Buscar" placeholder="ROL, ingreso, caratulado o relator" clearable />
            <flux:select wire:model.live="corte" label="Corte">
                <option value="">Todas las Cortes</option>
                @foreach ($this->cortes as $item)
                    <option value="{{ $item['codigo'] }}">{{ $item['nombre'] }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="sala" label="Sala">
                <option value="">Todas las salas</option>
                @foreach ($this->salas as $item)
                    <option value="{{ $item['numero'] }}">{{ $item['nombre'] }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="estado" label="Estado">
                <option value="">Todas</option>
                <option value="proximas">Próximas</option>
                <option value="realizadas">Fecha pasada</option>
                <option value="reprogramadas">Reprogramadas</option>
            </flux:select>
            @if ($this->puedeVerTodas)
                <flux:select wire:model.live="responsable" label="Responsable">
                    <option value="">Todos</option>
                    <option value="sin_responsable">Sin responsable</option>
                    @foreach ($this->responsables as $usuario)
                        <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input wire:model.live="desde" label="Vista desde" type="date" />
            <flux:input wire:model.live="hasta" label="Vista hasta" type="date" />
        </div>
        <flux:button variant="ghost" icon="x-mark" wire:click="limpiarFiltros" class="w-fit">Limpiar filtros</flux:button>
    </flux:card>

    <flux:card>
        <flux:table :paginate="$this->programaciones">
            <flux:table.columns>
                <flux:table.column>Corte / sala</flux:table.column>
                <flux:table.column>Causa</flux:table.column>
                @if ($this->puedeVerTodas)
                    <flux:table.column>Responsable</flux:table.column>
                @endif
                <flux:table.column>Estado</flux:table.column>
                <flux:table.column align="end">Acciones</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @php($diaAnterior = null)
                @forelse ($this->programaciones as $programacion)
                    @php($reprogramada = in_array($programacion->id, $this->reprogramadasEnPagina, true))
                    @php($dia = $programacion->fecha_vista->toDateString())
                    @if ($dia !== $diaAnterior)
                        @php($diaAnterior = $dia)
                        <flux:table.row :key="'dia-'.$dia">
                            <flux:table.cell :colspan="$this->puedeVerTodas ? 5 : 4" class="bg-zinc-50 py-2 dark:bg-zinc-800/60">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ ucfirst($programacion->fecha_vista->translatedFormat('l j \d\e F \d\e Y')) }}</span>
                                    @if ($programacion->fecha_vista->isToday())
                                        <flux:badge color="blue" size="sm">Hoy</flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-500">{{ $this->causasPorDia[$dia] }} {{ $this->causasPorDia[$dia] === 1 ? 'causa' : 'causas' }}</span>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                    <flux:table.row :key="$programacion->id">
                        <flux:table.cell class="min-w-48 whitespace-normal break-words">
                            <span>{{ $programacion->tribunal_nombre }}</span>
                            <br><span class="text-xs text-zinc-500">{{ $programacion->sala }} · Tabla {{ $programacion->tipo_tabla }}@if ($programacion->numero_orden) · N° orden {{ $programacion->numero_orden }}@endif</span>
                            @if ($programacion->relator)
                                <br><span class="text-xs text-zinc-500">Relator: {{ $programacion->relator }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="min-w-48 whitespace-normal break-words">
                            @if ($programacion->causa && ! $programacion->causa->trashed() && auth()->user()->can('view', $programacion->causa))
                                <a href="{{ route('causas.show', $programacion->causa) }}" wire:navigate class="font-medium text-blue-700 hover:underline dark:text-blue-400">{{ $programacion->causa->numero_causa ?? $programacion->causa->nombre }}</a>
                            @else
                                <span class="font-medium">{{ $programacion->causa?->numero_causa ?? $programacion->causa?->nombre ?? '—' }}</span>
                            @endif
                            <br><span class="text-xs text-zinc-500">Ingreso PJUD: {{ $programacion->numero_ingreso }}</span>
                            @if ($programacion->caratulado)
                                <br><span class="text-xs text-zinc-500">{{ $programacion->caratulado }}</span>
                            @endif
                        </flux:table.cell>
                        @if ($this->puedeVerTodas)
                            <flux:table.cell class="min-w-32 whitespace-normal break-words">{{ $programacion->causa?->responsable?->name ?? 'Sin responsable' }}</flux:table.cell>
                        @endif
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @if ($reprogramada)
                                    <flux:badge color="amber" size="sm">Reprogramada</flux:badge>
                                @elseif ($programacion->fecha_vista->isBefore(today()))
                                    <flux:badge color="zinc" size="sm">Fecha pasada</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">Próxima</flux:badge>
                                @endif
                                @if ($programacion->tipo_coincidencia !== 'exacta')
                                    <flux:badge color="orange" size="sm">Coincidencia {{ $programacion->tipo_coincidencia }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" icon="building-library" wire:click="verSala({{ $programacion->id }})">Ver sala</flux:button>
                            <flux:button size="sm" variant="ghost" icon="clock" wire:click="verHistorial({{ $programacion->id }})">Historial</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell :colspan="$this->puedeVerTodas ? 5 : 4" class="py-10 text-center">No hay causas agendadas en salas para los filtros seleccionados.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showHistorial" class="md:min-w-3xl">
        @if ($this->seleccionada)
            <div class="grid gap-6">
                <div>
                    <flux:heading size="lg">Historial de la causa {{ $this->seleccionada->causa?->numero_causa ?? $this->seleccionada->numero_ingreso }}</flux:heading>
                    <flux:text>{{ $this->seleccionada->causa?->nombre ?? $this->seleccionada->caratulado }}</flux:text>
                </div>

                <div class="grid gap-3">
                    <flux:heading size="sm">Agendamientos en salas</flux:heading>
                    <ol class="grid gap-3 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                        @foreach ($this->historial as $fila)
                            @php($item = $fila['programacion'])
                            <li class="grid gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $item->fecha_vista->format('d/m/Y') }} · {{ $item->sala }}</span>
                                    @if ($item->id === $this->seleccionada->id)
                                        <flux:badge color="blue" size="sm">Seleccionada</flux:badge>
                                    @endif
                                    @if ($fila['cambios'] !== [])
                                        <flux:badge color="amber" size="sm">Modificación</flux:badge>
                                    @endif
                                </div>
                                <span class="text-sm text-zinc-500">{{ $item->tribunal_nombre }} · Tabla {{ $item->tipo_tabla }}@if ($item->numero_orden) · N° orden {{ $item->numero_orden }}@endif</span>
                                @foreach ($fila['cambios'] as $cambio)
                                    <span class="text-sm text-amber-700 dark:text-amber-400">{{ $cambio }}</span>
                                @endforeach
                                <span class="text-xs text-zinc-500">
                                    Detectada {{ $item->detectada_en?->format('d/m/Y H:i') ?? '—' }}
                                    · {{ $item->notificado_at ? 'Avisada en Google Calendar el '.$item->notificado_at->format('d/m/Y H:i') : 'Sin aviso en Google Calendar' }}
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="grid gap-3">
                    <flux:heading size="sm">Movimientos en el Estado Diario</flux:heading>
                    @forelse ($this->movimientos as $movimiento)
                        <div class="grid gap-1 border-b border-zinc-100 pb-2 text-sm dark:border-zinc-800">
                            <span class="font-medium">{{ $movimiento->fecha_estado_diario->format('d/m/Y') }} · {{ $movimiento->tribunal_nombre }}</span>
                            @if ($movimiento->descripcionTramite())<span>{{ $movimiento->descripcionTramite() }}</span>@endif
                            @if ($movimiento->detalle)<span class="text-zinc-500">{{ $movimiento->detalle }}</span>@endif
                        </div>
                    @empty
                        <flux:text>No hay movimientos del Estado Diario registrados para esta causa.</flux:text>
                    @endforelse
                </div>

                <div class="flex justify-end"><flux:modal.close><flux:button variant="ghost">Cerrar</flux:button></flux:modal.close></div>
            </div>
        @endif
    </flux:modal>
</div>
