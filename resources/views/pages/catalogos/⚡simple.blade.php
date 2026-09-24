<?php

use App\Enums\Permiso;
use App\Models\Accion;
use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Materia;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Catálogos')] class extends Component {
    use WithPagination;

    #[Locked]
    public string $catalogo = '';

    public string $search = '';

    public string $sortDirection = 'asc';

    public string $nombre = '';

    public bool $activo = true;

    public bool $showModal = false;

    #[Locked]
    public ?int $editingId = null;

    public function mount(string $catalogo): void
    {
        Gate::authorize(Permiso::CatalogosVer->value);

        abort_unless(array_key_exists($catalogo, $this->definitions()), 404);

        $this->catalogo = $catalogo;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleOrder(): void
    {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    /** @return array{title: string, singular: string, description: string, model: class-string<Model>, max: int} */
    #[Computed]
    public function definition(): array
    {
        return $this->definitions()[$this->catalogo];
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        $modelClass = $this->definition['model'];

        return $modelClass::query()
            ->select(['id', 'nombre', 'activo'])
            ->when($this->search !== '', fn ($query) => $query->where('nombre', 'like', '%'.trim($this->search).'%'))
            ->orderBy('nombre', $this->sortDirection)
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        Gate::authorize(Permiso::CatalogosCrear->value);

        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $recordId): void
    {
        Gate::authorize(Permiso::CatalogosEditar->value);

        $record = $this->findRecord($recordId);

        $this->resetValidation();
        $this->editingId = $record->getKey();
        $this->nombre = (string) $record->getAttribute('nombre');
        $this->activo = (bool) $record->getAttribute('activo');
        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize($this->editingId === null ? Permiso::CatalogosCrear->value : Permiso::CatalogosEditar->value);

        $this->nombre = Str::squish($this->nombre);
        $modelClass = $this->definition['model'];

        $validated = $this->validate([
            'nombre' => [
                'required',
                'string',
                'max:'.$this->definition['max'],
                Rule::unique($modelClass, 'nombre')->ignore($this->editingId),
            ],
            'activo' => ['required', 'boolean'],
        ], attributes: [
            'nombre' => 'nombre',
            'activo' => 'estado',
        ]);

        if ($this->editingId === null) {
            $modelClass::query()->create($validated);
            $message = $this->definition['singular'].' creado correctamente.';
        } else {
            $this->findRecord($this->editingId)->update($validated);
            $message = $this->definition['singular'].' actualizado correctamente.';
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->records);

        Flux::toast(variant: 'success', text: $message);
    }

    public function toggleStatus(int $recordId): void
    {
        Gate::authorize(Permiso::CatalogosDesactivar->value);

        $record = $this->findRecord($recordId);
        $record->update(['activo' => ! $record->getAttribute('activo')]);
        unset($this->records);

        Flux::toast(variant: 'success', text: 'Estado actualizado correctamente.');
    }

    private function findRecord(int $recordId): Model
    {
        $modelClass = $this->definition['model'];

        return $modelClass::query()->findOrFail($recordId);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->nombre = '';
        $this->activo = true;
    }

    /** @return array<string, array{title: string, singular: string, description: string, model: class-string<Model>, max: int}> */
    private function definitions(): array
    {
        return [
            'materias' => [
                'title' => 'Materias',
                'singular' => 'Materia',
                'description' => 'Clasificaciones jurídicas principales utilizadas en las causas.',
                'model' => Materia::class,
                'max' => 150,
            ],
            'ciudades' => [
                'title' => 'Ciudades',
                'singular' => 'Ciudad',
                'description' => 'Ciudades asociadas a los juzgados y tribunales.',
                'model' => Ciudad::class,
                'max' => 150,
            ],
            'direcciones' => [
                'title' => 'Direcciones',
                'singular' => 'Dirección',
                'description' => 'Direcciones o unidades responsables vinculadas a las causas.',
                'model' => Direccion::class,
                'max' => 180,
            ],
            'estados-procesales' => [
                'title' => 'Estados procesales',
                'singular' => 'Estado procesal',
                'description' => 'Etapas que describen el avance procesal de una causa.',
                'model' => EstadoProcesal::class,
                'max' => 150,
            ],
            'estados-causa' => [
                'title' => 'Estados de causa',
                'singular' => 'Estado de causa',
                'description' => 'Estados generales para identificar causas vigentes o cerradas.',
                'model' => EstadoCausa::class,
                'max' => 100,
            ],
            'acciones' => [
                'title' => 'Acciones',
                'singular' => 'Acción',
                'description' => 'Acciones jurídicas disponibles para clasificar cada causa.',
                'model' => Accion::class,
                'max' => 180,
            ],
        ];
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Catálogos</flux:badge>
            <flux:heading size="xl" level="1">{{ $this->definition['title'] }}</flux:heading>
            <flux:text>{{ $this->definition['description'] }}</flux:text>
        </div>

        @can(\App\Enums\Permiso::CatalogosCrear->value)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Nueva {{ Str::lower($this->definition['singular']) }}
            </flux:button>
        @endcan
    </div>

    <flux:card class="grid gap-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <flux:heading size="lg">Registros</flux:heading>
                <flux:text>Consulta y administra los valores disponibles.</flux:text>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Buscar por nombre"
                class="sm:max-w-xs"
                clearable
            />
        </div>

        <flux:table :paginate="$this->records">
            <flux:table.columns>
                <flux:table.column>
                    <button type="button" class="inline-flex items-center gap-1 font-medium" wire:click="toggleOrder">
                        Nombre
                        <flux:icon.arrows-up-down class="size-4" />
                    </button>
                </flux:table.column>
                <flux:table.column>Estado</flux:table.column>
                @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                    <flux:table.column align="end">Acciones</flux:table.column>
                @endcanany
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->records as $record)
                    <flux:table.row :key="$catalogo.'-'.$record->id">
                        <flux:table.cell variant="strong">{{ $record->nombre }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$record->activo ? 'green' : 'zinc'" size="sm">
                                {{ $record->activo ? 'Activo' : 'Inactivo' }}
                            </flux:badge>
                        </flux:table.cell>
                        @canany([\App\Enums\Permiso::CatalogosEditar->value, \App\Enums\Permiso::CatalogosDesactivar->value])
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @can(\App\Enums\Permiso::CatalogosEditar->value)
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $record->id }})">Editar</flux:button>
                                    @endcan
                                    @can(\App\Enums\Permiso::CatalogosDesactivar->value)
                                        <flux:button size="sm" variant="ghost" :icon="$record->activo ? 'no-symbol' : 'check-circle'" wire:click="toggleStatus({{ $record->id }})">{{ $record->activo ? 'Desactivar' : 'Activar' }}</flux:button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                        @endcanany
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="py-10 text-center">No se encontraron registros.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:min-w-lg">
        <form wire:submit="save" class="grid gap-6">
            <div>
                <flux:heading size="lg">{{ $editingId === null ? 'Crear' : 'Editar' }} {{ Str::lower($this->definition['singular']) }}</flux:heading>
                <flux:text>Completa la información del catálogo.</flux:text>
            </div>

            <flux:input wire:model="nombre" label="Nombre" required autofocus />
            <flux:switch wire:model="activo" label="Registro activo" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Guardar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
