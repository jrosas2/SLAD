<?php

use App\Models\Accion;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Causas')] class extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $materiaFilter = '';

    #[Url(except: '')]
    public string $submateriaFilter = '';

    #[Url(except: '')]
    public string $responsableFilter = '';

    #[Url(except: '')]
    public string $estadoProcesalFilter = '';

    #[Url(except: '')]
    public string $estadoCausaFilter = '';

    #[Url(except: '')]
    public string $direccionFilter = '';

    #[Url(except: '')]
    public string $demandanteDemandadoFilter = '';

    #[Url(except: '')]
    public string $ciudadFilter = '';

    #[Url(except: '')]
    public string $juzgadoFilter = '';

    #[Url(except: '')]
    public string $accionFilter = '';

    #[Url(except: '')]
    public string $fechaCausaDesde = '';

    #[Url(except: '')]
    public string $fechaCausaHasta = '';

    #[Url(except: '')]
    public string $fechaIngresoDesde = '';

    #[Url(except: '')]
    public string $fechaIngresoHasta = '';

    #[Url(except: 'id')]
    public string $sortBy = 'id';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    public bool $showFilters = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingId = null;

    #[Locked]
    public bool $openPrintDialog = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', Causa::class);
        $this->openPrintDialog = request()->boolean('imprimir');
    }

    public function updated(string $property): void
    {
        if (in_array($property, $this->filterProperties(), true)) {
            $this->resetPage();
        }
    }

    public function updatedMateriaFilter(): void
    {
        if ($this->submateriaFilter !== '' && ! Submateria::query()
            ->whereKey($this->submateriaFilter)
            ->where('materia_id', $this->materiaFilter)
            ->exists()) {
            $this->submateriaFilter = '';
        }

        unset($this->submaterias);
    }

    public function updatedCiudadFilter(): void
    {
        if ($this->juzgadoFilter !== '' && ! Juzgado::query()
            ->whereKey($this->juzgadoFilter)
            ->where('ciudad_id', $this->ciudadFilter)
            ->exists()) {
            $this->juzgadoFilter = '';
        }

        unset($this->juzgados);
    }

    public function sort(string $column): void
    {
        abort_unless(in_array($column, ['numero_causa', 'nombre', 'fecha_causa', 'monto_demandado'], true), 400);

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset($this->filterProperties());
        $this->resetPage();
        unset($this->records, $this->submaterias, $this->juzgados);
    }

    public function requestDelete(int $causaId): void
    {
        $causa = Causa::query()->findOrFail($causaId);
        Gate::authorize('delete', $causa);

        $this->deletingId = $causa->id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        $causa = Causa::query()->findOrFail($this->deletingId);
        Gate::authorize('delete', $causa);

        $causa->delete();
        $this->showDeleteModal = false;
        $this->deletingId = null;
        unset($this->records);

        Flux::toast(variant: 'success', text: 'Causa eliminada de forma segura.');
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return $this->recordsQuery()->paginate(15);
    }

    /** @return Collection<int, Causa> */
    #[Computed]
    public function printRecords(): Collection
    {
        return $this->recordsQuery()->get();
    }

    /** @return Builder<Causa> */
    private function recordsQuery(): Builder
    {
        $query = Causa::query()
            ->visibleFor(auth()->user())
            ->select([
                'id', 'nombre', 'numero_causa', 'fecha_causa', 'juzgado_id', 'materia_id',
                'submateria_id', 'responsable_id', 'estado_procesal_id', 'estado_causa_id',
                'direccion_id', 'demandante_demandado', 'monto_demandado',
            ])
            ->with([
                'juzgado:id,ciudad_id,nombre',
                'juzgado.ciudad:id,nombre',
                'materia:id,nombre',
                'submateria:id,nombre',
                'responsable:id,codigo,name',
                'estadoProcesal:id,nombre',
                'estadoCausa:id,nombre',
                'direccion:id,nombre',
            ]);

        $this->applySearch($query);
        $this->applyFilters($query);

        $sortBy = in_array($this->sortBy, ['numero_causa', 'nombre', 'fecha_causa', 'monto_demandado'], true)
            ? $this->sortBy
            : 'id';
        $sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'id') {
            $query->orderByDesc('id');
        } else {
            $query->orderBy($sortBy, $sortDirection)->orderByDesc('id');
        }

        return $query;
    }

    /** @return Collection<int, Materia> */
    #[Computed]
    public function materias(): Collection
    {
        return Materia::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, Submateria> */
    #[Computed]
    public function submaterias(): Collection
    {
        return Submateria::query()
            ->select(['id', 'materia_id', 'nombre'])
            ->when($this->materiaFilter !== '', fn (Builder $query) => $query->where('materia_id', $this->materiaFilter))
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function responsables(): Collection
    {
        return User::query()
            ->select(['id', 'codigo', 'name'])
            ->when(! auth()->user()?->can(\App\Enums\Permiso::CausasVerTodas->value), fn (Builder $query) => $query->whereKey(auth()->id()))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, EstadoProcesal> */
    #[Computed]
    public function estadosProcesales(): Collection
    {
        return EstadoProcesal::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, EstadoCausa> */
    #[Computed]
    public function estadosCausa(): Collection
    {
            return EstadoCausa::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, Direccion> */
    #[Computed]
    public function direcciones(): Collection
    {
        return Direccion::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, Ciudad> */
    #[Computed]
    public function ciudades(): Collection
    {
        return Ciudad::query()->select(['id', 'nombre'])->whereHas('juzgados')->orderBy('nombre')->get();
    }

    /** @return Collection<int, Juzgado> */
    #[Computed]
    public function juzgados(): Collection
    {
        return Juzgado::query()
            ->select(['id', 'ciudad_id', 'nombre'])
            ->when($this->ciudadFilter !== '', fn (Builder $query) => $query->where('ciudad_id', $this->ciudadFilter))
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, Accion> */
    #[Computed]
    public function acciones(): Collection
    {
        return Accion::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    #[Computed]
    public function activeFiltersCount(): int
    {
        return collect($this->filterProperties())
            ->reject(fn (string $property): bool => $property === 'search')
            ->filter(fn (string $property): bool => $this->{$property} !== '')
            ->count();
    }

    private function applySearch(Builder $query): void
    {
        if (trim($this->search) === '') {
            return;
        }

        $search = '%'.trim($this->search).'%';

        $query->where(function (Builder $query) use ($search): void {
            $query->where('numero_causa', 'like', $search)
                ->orWhere('nombre', 'like', $search)
                ->orWhere('demandante_demandado', 'like', $search)
                ->orWhereIn('responsable_id', User::query()
                    ->select('id')
                    ->where(function (Builder $query) use ($search): void {
                        $query->where('codigo', 'like', $search)
                            ->orWhere('name', 'like', $search);
                    }))
                ->orWhereIn('juzgado_id', Juzgado::query()
                    ->select('id')
                    ->where(function (Builder $query) use ($search): void {
                        $query->where('nombre', 'like', $search)
                            ->orWhereIn('ciudad_id', Ciudad::query()->select('id')->where('nombre', 'like', $search));
                    }))
                ->orWhereIn('direccion_id', Direccion::query()->select('id')->where('nombre', 'like', $search));
        });
    }

    private function applyFilters(Builder $query): void
    {
        $query
            ->when($this->materiaFilter !== '', fn (Builder $query) => $query->where('materia_id', $this->materiaFilter))
            ->when($this->submateriaFilter !== '', fn (Builder $query) => $query->where('submateria_id', $this->submateriaFilter))
            ->when($this->responsableFilter !== '', fn (Builder $query) => $query->where('responsable_id', $this->responsableFilter))
            ->when($this->estadoProcesalFilter !== '', fn (Builder $query) => $query->where('estado_procesal_id', $this->estadoProcesalFilter))
            ->when($this->estadoCausaFilter !== '', fn (Builder $query) => $query->where('estado_causa_id', $this->estadoCausaFilter))
            ->when($this->direccionFilter !== '', fn (Builder $query) => $query->where('direccion_id', $this->direccionFilter))
            ->when($this->demandanteDemandadoFilter !== '', fn (Builder $query) => $query->where('demandante_demandado', 'like', '%'.trim($this->demandanteDemandadoFilter).'%'))
            ->when($this->juzgadoFilter !== '', fn (Builder $query) => $query->where('juzgado_id', $this->juzgadoFilter))
            ->when($this->ciudadFilter !== '', fn (Builder $query) => $query->whereIn(
                'juzgado_id',
                Juzgado::query()->select('id')->where('ciudad_id', $this->ciudadFilter),
            ))
            ->when($this->accionFilter !== '', fn (Builder $query) => $query->where('accion_id', $this->accionFilter))
            ->when($this->fechaCausaDesde !== '', fn (Builder $query) => $query->where('fecha_causa', '>=', $this->fechaCausaDesde))
            ->when($this->fechaCausaHasta !== '', fn (Builder $query) => $query->where('fecha_causa', '<=', $this->fechaCausaHasta))
            ->when($this->fechaIngresoDesde !== '', fn (Builder $query) => $query->where('fecha_ingreso', '>=', $this->fechaIngresoDesde))
            ->when($this->fechaIngresoHasta !== '', fn (Builder $query) => $query->where('fecha_ingreso', '<=', $this->fechaIngresoHasta));
    }

    /** @return list<string> */
    private function filterProperties(): array
    {
        return [
            'search', 'materiaFilter', 'submateriaFilter', 'responsableFilter',
            'estadoProcesalFilter', 'estadoCausaFilter', 'direccionFilter', 'demandanteDemandadoFilter', 'ciudadFilter', 'juzgadoFilter',
            'accionFilter', 'fechaCausaDesde', 'fechaCausaHasta', 'fechaIngresoDesde',
            'fechaIngresoHasta',
        ];
    }
}; ?>

<div class="flex min-w-0 w-full flex-1 flex-col gap-6">
    @if ($openPrintDialog)
        <div x-data x-init="window.setTimeout(() => window.print(), 250)" wire:ignore></div>
    @endif

    <div class="causas-screen-only grid gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Gestión jurídica</flux:badge>
            <flux:heading size="xl" level="1">Causas</flux:heading>
            <flux:text>Consulta y administra los expedientes jurídicos registrados en SLAD.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" variant="primary" color="blue" x-on:click="const url = new URL(window.location.href); url.searchParams.set('imprimir', '1'); url.searchParams.delete('page'); window.open(url.toString(), '_blank');" wire:ignore>Imprimir listado</flux:button>
            @can('create', \App\Models\Causa::class)
                <flux:button variant="primary" icon="plus" :href="route('causas.create')" wire:navigate>Nueva causa</flux:button>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" heading="{{ session('status') }}" />
    @endif

    <flux:card class="grid min-w-0 gap-5">
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-end">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Buscar causas" placeholder="Número, nombre, parte, dirección, responsable, ciudad o juzgado" clearable />
            <flux:button icon="funnel" wire:click="$toggle('showFilters')">
                Filtros
                @if ($this->activeFiltersCount > 0)
                    <flux:badge color="blue" size="sm">{{ $this->activeFiltersCount }}</flux:badge>
                @endif
            </flux:button>
            <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar</flux:button>
        </div>

        @if ($showFilters)
            <div class="grid gap-4 rounded-sm border border-[#c5c6cd] bg-[#f8f9ff] p-4 md:grid-cols-2 xl:grid-cols-4 dark:border-slate-700 dark:bg-slate-900">
                <flux:select wire:model.live="materiaFilter" label="Materia"><option value="">Todas</option>@foreach ($this->materias as $materia)<option value="{{ $materia->id }}">{{ $materia->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="submateriaFilter" label="Submateria"><option value="">Todas</option>@foreach ($this->submaterias as $submateria)<option value="{{ $submateria->id }}">{{ $submateria->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="responsableFilter" label="Responsable"><option value="">Todos</option>@foreach ($this->responsables as $responsable)<option value="{{ $responsable->id }}">{{ $responsable->etiquetaResponsable() }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="estadoProcesalFilter" label="Estado procesal"><option value="">Todos</option>@foreach ($this->estadosProcesales as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="estadoCausaFilter" label="Estado de causa"><option value="">Todos</option>@foreach ($this->estadosCausa as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="direccionFilter" label="Dirección"><option value="">Todas</option>@foreach ($this->direcciones as $direccion)<option value="{{ $direccion->id }}">{{ $direccion->nombre }}</option>@endforeach</flux:select>
                <flux:input wire:model.live.debounce.300ms="demandanteDemandadoFilter" label="Demandante / demandado" placeholder="Buscar parte" clearable />
                <flux:select wire:model.live="accionFilter" label="Acción"><option value="">Todas</option>@foreach ($this->acciones as $accion)<option value="{{ $accion->id }}">{{ $accion->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="ciudadFilter" label="Ciudad"><option value="">Todas</option>@foreach ($this->ciudades as $ciudad)<option value="{{ $ciudad->id }}">{{ $ciudad->nombre }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="juzgadoFilter" label="Juzgado"><option value="">Todos</option>@foreach ($this->juzgados as $juzgado)<option value="{{ $juzgado->id }}">{{ $juzgado->nombre }}</option>@endforeach</flux:select>
                <flux:input wire:model.live="fechaCausaDesde" type="date" label="Fecha de causa desde" />
                <flux:input wire:model.live="fechaCausaHasta" type="date" label="Fecha de causa hasta" />
                <flux:input wire:model.live="fechaIngresoDesde" type="date" label="Fecha de ingreso desde" />
                <flux:input wire:model.live="fechaIngresoHasta" type="date" label="Fecha de ingreso hasta" />
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 pb-4 text-sm dark:border-zinc-700">
            <span class="font-medium">Ordenar por:</span>
            <flux:button size="sm" variant="ghost" wire:click="sort('numero_causa')">Número</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="sort('nombre')">Nombre</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="sort('fecha_causa')">Fecha</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="sort('monto_demandado')">Monto</flux:button>
        </div>

        <div id="causas-list" class="grid gap-3" wire:loading.class="opacity-60" wire:target="search,materiaFilter,submateriaFilter,responsableFilter,estadoProcesalFilter,estadoCausaFilter,direccionFilter,demandanteDemandadoFilter,ciudadFilter,juzgadoFilter,accionFilter,fechaCausaDesde,fechaCausaHasta,fechaIngresoDesde,fechaIngresoHasta,sort">
            @forelse ($this->records as $causa)
                <article wire:key="causa-card-{{ $causa->id }}" class="grid gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-mono text-sm font-semibold">{{ $causa->numero_causa ?? 'Sin número' }}</span><flux:badge color="blue" size="sm">{{ $causa->materia->nombre }}</flux:badge></div>
                            <h2 class="mt-1 break-words font-semibold text-zinc-900 dark:text-white">{{ $causa->nombre }}</h2>
                        </div>
                        <div class="flex flex-wrap gap-1 sm:justify-end">
                            <flux:button size="sm" variant="ghost" icon="eye" :href="route('causas.show', $causa)" wire:navigate>Ver</flux:button>
                            @can('update', $causa)<flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('causas.edit', $causa)" wire:navigate>Editar</flux:button>@endcan
                            @can('delete', $causa)<flux:button size="sm" variant="ghost" icon="trash" wire:click="requestDelete({{ $causa->id }})">Eliminar</flux:button>@endcan
                        </div>
                    </div>

                    <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Ciudad / juzgado</dt><dd class="mt-1 break-words">{{ $causa->juzgado?->ciudad?->nombre ?? '—' }}<span class="block text-xs text-zinc-500">{{ $causa->juzgado?->nombre ?? 'Sin juzgado' }}</span></dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Submateria</dt><dd class="mt-1 break-words">{{ $causa->submateria?->nombre ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Dirección</dt><dd class="mt-1 break-words">{{ $causa->direccion?->nombre ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Demandante / demandado</dt><dd class="mt-1 break-words">{{ $causa->demandante_demandado ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Responsable</dt><dd class="mt-1 break-words">{{ $causa->responsable?->etiquetaResponsable() ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Fechas y monto</dt><dd class="mt-1">{{ $causa->fecha_causa?->format('d-m-Y') ?? 'Sin fecha' }}<span class="block font-medium">{{ $causa->monto_demandado === null ? 'Sin monto' : '$ '.number_format((float) $causa->monto_demandado, 0, ',', '.') }}</span></dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Estado procesal</dt><dd class="mt-1"><flux:badge color="blue" size="sm">{{ $causa->estadoProcesal?->nombre ?? 'Sin estado' }}</flux:badge></dd></div>
                        <div><dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Estado de causa</dt><dd class="mt-1"><flux:badge :color="match (mb_strtoupper($causa->estadoCausa?->nombre ?? '')) { 'VIGENTE' => 'green', 'CERRADA' => 'red', default => 'zinc' }" size="sm">{{ $causa->estadoCausa?->nombre ?? 'Sin estado' }}</flux:badge></dd></div>
                    </dl>
                </article>
            @empty
                <div class="py-12 text-center text-sm text-zinc-500">No se encontraron causas con los criterios seleccionados.</div>
            @endforelse
        </div>

        <flux:pagination :paginator="$this->records" scroll-to="#causas-list" />
    </flux:card>

    <flux:modal wire:model="showDeleteModal" class="md:max-w-md">
        <div class="grid gap-5">
            <div class="grid gap-2"><flux:heading size="lg">Eliminar causa</flux:heading><flux:text>La causa dejará de aparecer en el sistema, pero sus datos se conservarán para recuperación administrativa.</flux:text></div>
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button variant="danger" icon="trash" wire:click="delete">Confirmar eliminación</flux:button></div>
        </div>
    </flux:modal>
    </div>

    @if ($openPrintDialog)
    <section class="causas-print-only causas-print-list" aria-label="Listado imprimible de causas">
        <header class="causas-print-header">
            <p class="causas-print-brand">SLAD</p>
            <p>Sistema Logístico de Administración de Derecho</p>
            <h1>Listado de causas</h1>
            <div><strong>Fecha de impresión:</strong> {{ now()->format('d-m-Y H:i') }}</div>
        </header>

        <table class="causas-print-table">
            <thead>
                <tr><th>N.º</th><th>Causa / materia</th><th>Ciudad / juzgado</th><th>Dirección</th><th>Responsable</th><th>Estados</th><th>Fecha</th><th>Monto</th></tr>
            </thead>
            <tbody>
                @forelse ($this->printRecords as $causa)
                    <tr>
                        <td>{{ $causa->numero_causa ?? '—' }}</td>
                        <td><strong>{{ $causa->nombre ?? '—' }}</strong><span>{{ $causa->materia?->nombre ?? '—' }}{{ $causa->submateria ? ' · '.$causa->submateria->nombre : '' }}</span></td>
                        <td>{{ $causa->juzgado?->ciudad?->nombre ?? '—' }}<span>{{ $causa->juzgado?->nombre ?? '—' }}</span></td>
                        <td>{{ $causa->direccion?->nombre ?? '—' }}</td>
                        <td>{{ $causa->responsable?->etiquetaResponsable() ?? '—' }}</td>
                        <td>{{ $causa->estadoProcesal?->nombre ?? '—' }}<span>{{ $causa->estadoCausa?->nombre ?? '—' }}</span></td>
                        <td>{{ $causa->fecha_causa?->format('d-m-Y') ?? '—' }}</td>
                        <td class="causas-print-amount">{{ $causa->monto_demandado === null ? '—' : '$'.number_format((float) $causa->monto_demandado, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="causas-print-empty">No se encontraron causas con los filtros seleccionados.</td></tr>
                @endforelse
            </tbody>
        </table>

        <p class="causas-print-total">Total de causas: {{ $this->printRecords->count() }}</p>
    </section>
    @endif

    <style>
    .causas-print-only { display: none; }

    @media print {
        @page { size: 13in 8.5in; margin: 10mm; }

        [data-flux-sidebar], ui-sidebar, ui-sidebar-toggle, ui-header, nav, .causas-screen-only { display: none !important; }
        html, body, .slad-main { min-height: 0 !important; height: auto !important; background: #fff !important; color: #111 !important; }
        .slad-main > [wire\:id] { flex: none !important; }
        .causas-print-only { display: block !important; }
        .causas-print-list { font-family: Arial, sans-serif; font-size: 8pt; line-height: 1.35; }
        .causas-print-header { border-bottom: 2px solid #111; margin-bottom: 5mm; padding-bottom: 3mm; }
        .causas-print-brand { font-size: 16pt; font-weight: 700; letter-spacing: .08em; margin: 0; }
        .causas-print-header p:not(.causas-print-brand) { margin: 0; }
        .causas-print-header h1 { font-size: 14pt; margin: 4mm 0 2mm; text-transform: uppercase; }
        .causas-print-table { border-collapse: collapse; width: 100%; }
        .causas-print-table th, .causas-print-table td { border: 1px solid #aaa; padding: 2mm; text-align: left; vertical-align: top; }
        .causas-print-table th { background: #eee; font-size: 7pt; text-transform: uppercase; }
        .causas-print-table td span { display: block; margin-top: 1mm; color: #555; }
        .causas-print-table tr { break-inside: avoid; page-break-inside: avoid; }
        .causas-print-amount { text-align: right !important; white-space: nowrap; }
        .causas-print-empty { padding: 8mm !important; text-align: center !important; }
        .causas-print-total { font-size: 9pt; font-weight: 700; margin-top: 4mm; text-align: right; }
    }
    </style>
</div>
