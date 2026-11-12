<?php

use App\Enums\Permiso;
use App\Models\PjudMovimiento;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Monitoreo de causas')] class extends Component
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

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permiso::MonitoreoVer->value), 403);
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
            ->orderByDesc('id')
            ->paginate(25);
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

    public function limpiarFiltros(): void
    {
        $this->reset('search', 'tribunal', 'responsable', 'desde', 'hasta');
        $this->resetPage();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
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
                            <span>{{ $movimiento->tramite ?? '—' }}</span>
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
</div>
