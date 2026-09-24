<?php

use App\Enums\Permiso;
use App\Models\Ciudad;
use App\Models\Juzgado;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Juzgados')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $ciudadFilter = '';

    public string $ciudadId = '';

    public string $nombre = '';

    public bool $activo = true;

    public bool $showModal = false;

    #[Locked]
    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize(Permiso::CatalogosVer->value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCiudadFilter(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Ciudad> */
    #[Computed]
    public function ciudades(): Collection
    {
        return Ciudad::query()
            ->select(['id', 'nombre', 'activo'])
            ->orderBy('nombre')
            ->get();
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return Juzgado::query()
            ->select(['id', 'ciudad_id', 'nombre', 'activo'])
            ->with('ciudad:id,nombre')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('nombre', 'like', $search)
                        ->orWhereHas('ciudad', fn (Builder $query) => $query->where('nombre', 'like', $search));
                });
            })
            ->when($this->ciudadFilter !== '', fn (Builder $query) => $query->where('ciudad_id', $this->ciudadFilter))
            ->orderBy('nombre')
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        Gate::authorize(Permiso::CatalogosCrear->value);

        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $juzgadoId): void
    {
        Gate::authorize(Permiso::CatalogosEditar->value);

        $juzgado = Juzgado::query()->findOrFail($juzgadoId);

        $this->resetValidation();
        $this->editingId = $juzgado->id;
        $this->ciudadId = (string) $juzgado->ciudad_id;
        $this->nombre = $juzgado->nombre;
        $this->activo = $juzgado->activo;
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize($this->editingId === null ? Permiso::CatalogosCrear->value : Permiso::CatalogosEditar->value);

        $this->nombre = Str::squish($this->nombre);

        $validated = $this->validate([
            'ciudadId' => ['required', 'integer', Rule::exists(Ciudad::class, 'id')],
            'nombre' => [
                'required',
                'string',
                'max:180',
                Rule::unique(Juzgado::class, 'nombre')
                    ->where(fn (QueryBuilder $query) => $query->where('ciudad_id', $this->ciudadId))
                    ->ignore($this->editingId),
            ],
            'activo' => ['required', 'boolean'],
        ], attributes: [
            'ciudadId' => 'ciudad',
            'nombre' => 'nombre',
            'activo' => 'estado',
        ]);

        $attributes = [
            'ciudad_id' => (int) $validated['ciudadId'],
            'nombre' => $validated['nombre'],
            'activo' => $validated['activo'],
        ];

        if ($this->editingId === null) {
            Juzgado::query()->create($attributes);
            $message = 'Juzgado creado correctamente.';
        } else {
            Juzgado::query()->findOrFail($this->editingId)->update($attributes);
            $message = 'Juzgado actualizado correctamente.';
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->records);

        Flux::toast(variant: 'success', text: $message);
    }

    public function toggleStatus(int $juzgadoId): void
    {
        Gate::authorize(Permiso::CatalogosDesactivar->value);

        $juzgado = Juzgado::query()->findOrFail($juzgadoId);
        $juzgado->update(['activo' => ! $juzgado->activo]);
        unset($this->records);

        Flux::toast(variant: 'success', text: 'Estado actualizado correctamente.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->ciudadId = '';
        $this->nombre = '';
        $this->activo = true;
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Catálogos</flux:badge>
            <flux:heading size="xl" level="1">Juzgados</flux:heading>
            <flux:text>Tribunales organizados por ciudad.</flux:text>
        </div>

        @can(\App\Enums\Permiso::CatalogosCrear->value)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">Nuevo juzgado</flux:button>
        @endcan
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-3 sm:grid-cols-2 sm:items-end">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Buscar" placeholder="Nombre o ciudad" clearable />
            <flux:select wire:model.live="ciudadFilter" label="Filtrar por ciudad">
                <option value="">Todas las ciudades</option>
                @foreach ($this->ciudades as $ciudad)
                    <option value="{{ $ciudad->id }}">{{ $ciudad->nombre }}</option>
                @endforeach
            </flux:select>
        </div>

        <flux:table :paginate="$this->records">
            <flux:table.columns>
                <flux:table.column>Juzgado</flux:table.column>
                <flux:table.column>Ciudad</flux:table.column>
                <flux:table.column>Estado</flux:table.column>
                @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                    <flux:table.column align="end">Acciones</flux:table.column>
                @endcanany
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->records as $juzgado)
                    <flux:table.row :key="$juzgado->id">
                        <flux:table.cell variant="strong">{{ $juzgado->nombre }}</flux:table.cell>
                        <flux:table.cell>{{ $juzgado->ciudad->nombre }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$juzgado->activo ? 'green' : 'zinc'" size="sm">{{ $juzgado->activo ? 'Activo' : 'Inactivo' }}</flux:badge>
                        </flux:table.cell>
                        @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @can(\App\Enums\Permiso::CatalogosEditar->value)
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $juzgado->id }})">Editar</flux:button>
                                    @endcan
                                    @can(\App\Enums\Permiso::CatalogosDesactivar->value)
                                        <flux:button size="sm" variant="ghost" :icon="$juzgado->activo ? 'no-symbol' : 'check-circle'" wire:click="toggleStatus({{ $juzgado->id }})">{{ $juzgado->activo ? 'Desactivar' : 'Activar' }}</flux:button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                        @endcanany
                    </flux:table.row>
                @empty
                    <flux:table.row>
                    <flux:table.cell colspan="4" class="py-10 text-center">No se encontraron juzgados.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:min-w-lg">
        <form wire:submit="save" class="grid gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? 'Crear' : 'Editar' }} juzgado</flux:heading>
                <flux:text>Registra el tribunal y su ciudad correspondiente.</flux:text>
            </div>

            <flux:select wire:model="ciudadId" label="Ciudad" required>
                <option value="">Selecciona una ciudad</option>
                @foreach ($this->ciudades as $ciudad)
                    <option value="{{ $ciudad->id }}">{{ $ciudad->nombre }}{{ $ciudad->activo ? '' : ' (inactiva)' }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="nombre" label="Nombre" required />
            <flux:switch wire:model="activo" label="Registro activo" />

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
