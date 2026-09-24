<?php

use App\Enums\Permiso;
use App\Models\Materia;
use App\Models\Submateria;
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

new #[Title('Submaterias')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $materiaFilter = '';

    public string $materiaId = '';

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

    public function updatedMateriaFilter(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Materia> */
    #[Computed]
    public function materias(): Collection
    {
        return Materia::query()
            ->select(['id', 'nombre', 'activo'])
            ->orderBy('nombre')
            ->get();
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        return Submateria::query()
            ->select(['id', 'materia_id', 'nombre', 'activo'])
            ->with('materia:id,nombre')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('nombre', 'like', $search)
                        ->orWhereHas('materia', fn (Builder $query) => $query->where('nombre', 'like', $search));
                });
            })
            ->when($this->materiaFilter !== '', fn (Builder $query) => $query->where('materia_id', $this->materiaFilter))
            ->orderBy('nombre')
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        Gate::authorize(Permiso::CatalogosCrear->value);

        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $submateriaId): void
    {
        Gate::authorize(Permiso::CatalogosEditar->value);

        $submateria = Submateria::query()->findOrFail($submateriaId);

        $this->resetValidation();
        $this->editingId = $submateria->id;
        $this->materiaId = (string) $submateria->materia_id;
        $this->nombre = $submateria->nombre;
        $this->activo = $submateria->activo;
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize($this->editingId === null ? Permiso::CatalogosCrear->value : Permiso::CatalogosEditar->value);

        $this->nombre = Str::squish($this->nombre);

        $validated = $this->validate([
            'materiaId' => ['required', 'integer', Rule::exists(Materia::class, 'id')],
            'nombre' => [
                'required',
                'string',
                'max:180',
                Rule::unique(Submateria::class, 'nombre')
                    ->where(fn (QueryBuilder $query) => $query->where('materia_id', $this->materiaId))
                    ->ignore($this->editingId),
            ],
            'activo' => ['required', 'boolean'],
        ], attributes: [
            'materiaId' => 'materia',
            'nombre' => 'nombre',
            'activo' => 'estado',
        ]);

        $attributes = [
            'materia_id' => (int) $validated['materiaId'],
            'nombre' => $validated['nombre'],
            'activo' => $validated['activo'],
        ];

        if ($this->editingId === null) {
            Submateria::query()->create($attributes);
            $message = 'Submateria creada correctamente.';
        } else {
            Submateria::query()->findOrFail($this->editingId)->update($attributes);
            $message = 'Submateria actualizada correctamente.';
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->records);

        Flux::toast(variant: 'success', text: $message);
    }

    public function toggleStatus(int $submateriaId): void
    {
        Gate::authorize(Permiso::CatalogosDesactivar->value);

        $submateria = Submateria::query()->findOrFail($submateriaId);
        $submateria->update(['activo' => ! $submateria->activo]);
        unset($this->records);

        Flux::toast(variant: 'success', text: 'Estado actualizado correctamente.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->materiaId = '';
        $this->nombre = '';
        $this->activo = true;
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Catálogos</flux:badge>
            <flux:heading size="xl" level="1">Submaterias</flux:heading>
            <flux:text>Clasificaciones específicas agrupadas dentro de cada materia jurídica.</flux:text>
        </div>

        @can(\App\Enums\Permiso::CatalogosCrear->value)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">Nueva submateria</flux:button>
        @endcan
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-3 sm:grid-cols-2 sm:items-end">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" label="Buscar" placeholder="Nombre o materia" clearable />
            <flux:select wire:model.live="materiaFilter" label="Filtrar por materia">
                <option value="">Todas las materias</option>
                @foreach ($this->materias as $materia)
                    <option value="{{ $materia->id }}">{{ $materia->nombre }}</option>
                @endforeach
            </flux:select>
        </div>

        <flux:table :paginate="$this->records">
            <flux:table.columns>
                <flux:table.column>Submateria</flux:table.column>
                <flux:table.column>Materia</flux:table.column>
                <flux:table.column>Estado</flux:table.column>
                @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                    <flux:table.column align="end">Acciones</flux:table.column>
                @endcanany
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->records as $submateria)
                    <flux:table.row :key="$submateria->id">
                        <flux:table.cell variant="strong">{{ $submateria->nombre }}</flux:table.cell>
                        <flux:table.cell>{{ $submateria->materia->nombre }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$submateria->activo ? 'green' : 'zinc'" size="sm">{{ $submateria->activo ? 'Activo' : 'Inactivo' }}</flux:badge>
                        </flux:table.cell>
                        @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @can(\App\Enums\Permiso::CatalogosEditar->value)
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $submateria->id }})">Editar</flux:button>
                                    @endcan
                                    @can(\App\Enums\Permiso::CatalogosDesactivar->value)
                                        <flux:button size="sm" variant="ghost" :icon="$submateria->activo ? 'no-symbol' : 'check-circle'" wire:click="toggleStatus({{ $submateria->id }})">{{ $submateria->activo ? 'Desactivar' : 'Activar' }}</flux:button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                        @endcanany
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-10 text-center">No se encontraron submaterias.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:min-w-lg">
        <form wire:submit="save" class="grid gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? 'Crear' : 'Editar' }} submateria</flux:heading>
                <flux:text>Relaciona la submateria con su clasificación principal.</flux:text>
            </div>

            <flux:select wire:model="materiaId" label="Materia" required>
                <option value="">Selecciona una materia</option>
                @foreach ($this->materias as $materia)
                    <option value="{{ $materia->id }}">{{ $materia->nombre }}{{ $materia->activo ? '' : ' (inactiva)' }}</option>
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
