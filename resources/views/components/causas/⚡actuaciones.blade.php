<?php

use App\Actions\Actuaciones\EliminarActuacion;
use App\Actions\Actuaciones\GuardarActuacion;
use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\EstadoProcesal;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Locked]
    public int $causaId;

    public string $fecha = '';

    public string $estadoProcesalId = '';

    public string $descripcion = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $editingEstadoProcesalId = null;

    #[Locked]
    public ?int $deletingId = null;

    public function mount(Causa $causa): void
    {
        Gate::authorize('view', $causa);
        $this->causaId = $causa->id;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('view', Causa::query()->findOrFail($this->causaId));
        Gate::authorize('create', Actuacion::class);

        $this->resetForm();
        $this->fecha = now()->toDateString();
        $this->showFormModal = true;
    }

    public function openEditModal(int $actuacionId): void
    {
        $actuacion = $this->findActuacion($actuacionId);
        Gate::authorize('update', $actuacion);

        $this->resetValidation();
        $this->editingId = $actuacion->id;
        $this->editingEstadoProcesalId = $actuacion->estado_procesal_id;
        $this->fecha = $actuacion->fecha?->format('Y-m-d') ?? '';
        $this->estadoProcesalId = $actuacion->estado_procesal_id === null ? '' : (string) $actuacion->estado_procesal_id;
        $this->descripcion = $actuacion->descripcion;
        $this->showFormModal = true;
        unset($this->estadosProcesales);
    }

    public function save(GuardarActuacion $guardarActuacion): void
    {
        $causa = Causa::query()->findOrFail($this->causaId);
        Gate::authorize('view', $causa);
        $actuacion = $this->editingId === null ? null : $this->findActuacion($this->editingId);
        Gate::authorize($actuacion === null ? 'create' : 'update', $actuacion ?? Actuacion::class);

        $this->descripcion = trim($this->descripcion);

        $validated = $this->validate([
            'causaId' => ['required', 'integer', Rule::exists(Causa::class, 'id')->whereNull('deleted_at')],
            'fecha' => ['required', 'date'],
            'estadoProcesalId' => [
                'nullable',
                'integer',
                Rule::exists(EstadoProcesal::class, 'id')->where(function (QueryBuilder $query): void {
                    $query->where('activo', true);

                    if ($this->editingEstadoProcesalId !== null) {
                        $query->orWhere('id', $this->editingEstadoProcesalId);
                    }
                }),
            ],
            'descripcion' => ['required', 'string', 'max:50000'],
        ], attributes: [
            'causaId' => 'causa',
            'fecha' => 'fecha',
            'estadoProcesalId' => 'estado procesal',
            'descripcion' => 'descripción',
        ]);

        $usuario = auth()->user();
        abort_unless($usuario instanceof User, 403);

        $guardarActuacion->handle($causa, [
            'fecha' => $validated['fecha'],
            'estado_procesal_id' => $validated['estadoProcesalId'] === '' ? null : (int) $validated['estadoProcesalId'],
            'descripcion' => $validated['descripcion'],
        ], $usuario, $actuacion);

        $message = $actuacion === null
            ? 'Actuación registrada correctamente.'
            : 'Actuación actualizada correctamente.';

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage(pageName: 'actuacionesPage');
        unset($this->actuaciones, $this->estadosProcesales);
        $this->dispatch('actuacion-saved');

        Flux::toast(variant: 'success', text: $message);
    }

    public function requestDelete(int $actuacionId): void
    {
        $actuacion = $this->findActuacion($actuacionId);
        Gate::authorize('delete', $actuacion);

        $this->deletingId = $actuacion->id;
        $this->showDeleteModal = true;
    }

    public function delete(EliminarActuacion $eliminarActuacion): void
    {
        $causa = Causa::query()->findOrFail($this->causaId);
        $actuacion = $this->findActuacion($this->deletingId);
        Gate::authorize('delete', $actuacion);

        $eliminarActuacion->handle($causa, $actuacion);

        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->resetPage(pageName: 'actuacionesPage');
        unset($this->actuaciones);
        $this->dispatch('actuacion-saved');

        Flux::toast(variant: 'success', text: 'Actuación eliminada de forma segura.');
    }

    #[Computed]
    public function actuaciones(): LengthAwarePaginator
    {
        return Actuacion::query()
            ->select(['id', 'causa_id', 'fecha', 'estado_procesal_id', 'descripcion', 'created_by', 'created_at'])
            ->with([
                'causa:id,responsable_id',
                'estadoProcesal:id,nombre',
                'creador:id,name',
            ])
            ->where('causa_id', $this->causaId)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(10, pageName: 'actuacionesPage');
    }

    /** @return Collection<int, EstadoProcesal> */
    #[Computed]
    public function estadosProcesales(): Collection
    {
        return EstadoProcesal::query()
            ->select(['id', 'nombre', 'activo'])
            ->where(function (Builder $query): void {
                $query->where('activo', true);

                if ($this->editingEstadoProcesalId !== null) {
                    $query->orWhere('id', $this->editingEstadoProcesalId);
                }
            })
            ->orderBy('nombre')
            ->get();
    }

    private function findActuacion(?int $actuacionId): Actuacion
    {
        return Actuacion::query()
            ->with('causa:id,responsable_id')
            ->where('causa_id', $this->causaId)
            ->findOrFail($actuacionId);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->editingEstadoProcesalId = null;
        $this->fecha = '';
        $this->estadoProcesalId = '';
        $this->descripcion = '';
    }
}; ?>

<section class="grid gap-5" aria-labelledby="actuaciones-heading">
    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div class="grid gap-2">
            <div class="flex items-center gap-2">
                <flux:heading id="actuaciones-heading" size="lg">Actuaciones</flux:heading>
                <flux:badge color="blue" size="sm">{{ $this->actuaciones->total() }}</flux:badge>
            </div>
            <flux:text>Historial procesal ordenado desde la actuación más reciente.</flux:text>
        </div>

        @can('create', \App\Models\Actuacion::class)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">Nueva actuación</flux:button>
        @endcan
    </div>

    <div wire:loading.class="opacity-60" wire:target="save,delete">
        @forelse ($this->actuaciones as $actuacion)
            <article wire:key="actuacion-{{ $actuacion->id }}" class="relative border-l-2 border-[#006a61]/30 pb-6 pl-7 last:border-transparent last:pb-0 dark:border-teal-400/30">
                <span class="absolute -left-[7px] top-5 size-3 rounded-full border-2 border-[#f8f9ff] bg-[#006a61] dark:border-[#08111f] dark:bg-teal-300"></span>
                <div class="grid gap-4 rounded-sm border border-[#c5c6cd] bg-white p-5 shadow-xs dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div class="flex flex-wrap items-center gap-2">
                            <time class="font-semibold text-[#091426] dark:text-slate-100">{{ $actuacion->fecha?->format('d-m-Y') ?? 'Sin fecha procesal' }}</time>
                            @if ($actuacion->estadoProcesal)
                                <flux:badge color="blue" size="sm">{{ $actuacion->estadoProcesal->nombre }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex gap-1">
                            @can('update', $actuacion)
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $actuacion->id }})">Editar</flux:button>
                            @endcan
                            @can('delete', $actuacion)
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="requestDelete({{ $actuacion->id }})">Eliminar</flux:button>
                            @endcan
                        </div>
                    </div>

                    <p class="whitespace-pre-wrap break-words text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $actuacion->descripcion }}</p>

                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Registrado por: {{ $actuacion->creador?->name ?? 'Usuario no disponible' }}</span>
                        <span>Incorporado: {{ $actuacion->created_at?->format('d-m-Y H:i') ?? '—' }}</span>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-sm border border-dashed border-[#c5c6cd] p-10 text-center dark:border-slate-700">
                <flux:heading size="lg">Sin actuaciones registradas</flux:heading>
                <flux:text class="mt-2">El historial procesal de esta causa todavía está vacío.</flux:text>
            </div>
        @endforelse
    </div>

    @if ($this->actuaciones->hasPages())
        <flux:pagination :paginator="$this->actuaciones" scroll-to="#actuaciones-heading" />
    @endif

    <flux:modal wire:model="showFormModal" class="md:min-w-xl" scroll="body">
        <form wire:submit="save" class="grid gap-6">
            <div class="grid gap-2">
                <flux:heading size="lg">{{ $editingId === null ? 'Nueva actuación' : 'Editar actuación' }}</flux:heading>
                <flux:text>Registra un evento procesal independiente dentro de esta causa.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="fecha" type="date" label="Fecha procesal" required />
                <flux:select wire:model="estadoProcesalId" label="Estado procesal">
                    <option value="">Sin estado procesal</option>
                    @foreach ($this->estadosProcesales as $estado)
                        <option value="{{ $estado->id }}">{{ $estado->nombre }}{{ $estado->activo ? '' : ' (inactivo)' }}</option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea wire:model="descripcion" label="Descripción" rows="8" resize="vertical" maxlength="50000" required />

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" icon="check" wire:loading.attr="disabled">Guardar actuación</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="md:max-w-md">
        <div class="grid gap-5">
            <div class="grid gap-2">
                <flux:heading size="lg">Eliminar actuación</flux:heading>
                <flux:text>El registro dejará de aparecer en el historial, pero permanecerá disponible para recuperación administrativa.</flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled">Confirmar eliminación</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
