<?php

use App\Actions\MovimientosFinancieros\EliminarMovimientoFinanciero;
use App\Actions\MovimientosFinancieros\GuardarMovimientoFinanciero;
use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\MovimientoFinanciero;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public string $tipo = 'INGRESO';

    public string $fecha = '';

    public string $monto = '';

    public string $observacion = '';

    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $deletingId = null;

    public function mount(Causa $causa): void
    {
        Gate::authorize('view', $causa);
        Gate::authorize('viewAny', MovimientoFinanciero::class);
        $this->causaId = $causa->id;
    }

    public function openCreateModal(string $tipo = 'INGRESO'): void
    {
        Gate::authorize('view', Causa::query()->findOrFail($this->causaId));
        Gate::authorize('create', MovimientoFinanciero::class);

        $tipoMovimiento = TipoMovimientoFinanciero::tryFrom($tipo);
        abort_if($tipoMovimiento === null, 422);

        $this->resetForm();
        $this->tipo = $tipoMovimiento->value;
        $this->fecha = now()->toDateString();
        $this->showFormModal = true;
    }

    public function openEditModal(int $movimientoId): void
    {
        $movimiento = $this->findMovimiento($movimientoId);
        Gate::authorize('update', $movimiento);

        $this->resetValidation();
        $this->editingId = $movimiento->id;
        $this->tipo = $movimiento->tipo->value;
        $this->fecha = $movimiento->fecha?->format('Y-m-d') ?? '';
        $this->monto = (string) (int) $movimiento->monto;
        $this->observacion = $movimiento->observacion ?? '';
        $this->showFormModal = true;
    }

    public function save(GuardarMovimientoFinanciero $guardarMovimiento): void
    {
        $causa = Causa::query()->findOrFail($this->causaId);
        Gate::authorize('view', $causa);
        $movimiento = $this->editingId === null ? null : $this->findMovimiento($this->editingId);
        Gate::authorize($movimiento === null ? 'create' : 'update', $movimiento ?? MovimientoFinanciero::class);

        $this->observacion = trim($this->observacion);

        $validated = $this->validate([
            'causaId' => ['required', 'integer', Rule::exists(Causa::class, 'id')->whereNull('deleted_at')],
            'tipo' => ['required', Rule::enum(TipoMovimientoFinanciero::class)],
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'integer', 'gt:0', 'max:9999999999999'],
            'observacion' => ['nullable', 'string', 'max:50000'],
        ], attributes: [
            'causaId' => 'causa',
            'tipo' => 'tipo de movimiento',
            'fecha' => 'fecha',
            'monto' => 'monto',
            'observacion' => 'observación',
        ]);

        $usuario = auth()->user();
        abort_unless($usuario instanceof User, 403);

        $guardarMovimiento->handle($causa, [
            'tipo' => TipoMovimientoFinanciero::from((string) $validated['tipo']),
            'fecha' => (string) $validated['fecha'],
            'monto' => (int) $validated['monto'],
            'observacion' => $validated['observacion'] === '' ? null : (string) $validated['observacion'],
        ], $usuario, $movimiento);

        $message = $movimiento === null
            ? 'Movimiento financiero registrado correctamente.'
            : 'Movimiento financiero actualizado correctamente.';

        $this->showFormModal = false;
        $this->resetForm();
        $this->resetPage(pageName: 'movimientosPage');
        unset($this->movimientos, $this->resumenFinanciero);

        Flux::toast(variant: 'success', text: $message);
    }

    public function requestDelete(int $movimientoId): void
    {
        $movimiento = $this->findMovimiento($movimientoId);
        Gate::authorize('delete', $movimiento);

        $this->deletingId = $movimiento->id;
        $this->showDeleteModal = true;
    }

    public function delete(EliminarMovimientoFinanciero $eliminarMovimiento): void
    {
        $causa = Causa::query()->findOrFail($this->causaId);
        $movimiento = $this->findMovimiento($this->deletingId);
        Gate::authorize('delete', $movimiento);

        $eliminarMovimiento->handle($causa, $movimiento);

        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->resetPage(pageName: 'movimientosPage');
        unset($this->movimientos, $this->resumenFinanciero);

        Flux::toast(variant: 'success', text: 'Movimiento financiero eliminado de forma segura.');
    }

    #[Computed]
    public function movimientos(): LengthAwarePaginator
    {
        return MovimientoFinanciero::query()
            ->select(['id', 'causa_id', 'tipo', 'monto', 'fecha', 'observacion', 'created_by', 'created_at'])
            ->with(['causa:id,responsable_id', 'creador:id,name'])
            ->where('causa_id', $this->causaId)
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(10, pageName: 'movimientosPage');
    }

    /** @return array{totalIngresos: int, totalEgresos: int, saldo: int} */
    #[Computed]
    public function resumenFinanciero(): array
    {
        $totalIngresos = (int) MovimientoFinanciero::query()
            ->where('causa_id', $this->causaId)
            ->where('tipo', TipoMovimientoFinanciero::Ingreso)
            ->sum('monto');
        $totalEgresos = (int) MovimientoFinanciero::query()
            ->where('causa_id', $this->causaId)
            ->where('tipo', TipoMovimientoFinanciero::Egreso)
            ->sum('monto');

        return [
            'totalIngresos' => $totalIngresos,
            'totalEgresos' => $totalEgresos,
            'saldo' => $totalIngresos - $totalEgresos,
        ];
    }

    public function formatClp(int|float|string $monto): string
    {
        return '$'.number_format((float) $monto, 0, ',', '.');
    }

    private function findMovimiento(?int $movimientoId): MovimientoFinanciero
    {
        return MovimientoFinanciero::query()
            ->with('causa:id,responsable_id')
            ->where('causa_id', $this->causaId)
            ->findOrFail($movimientoId);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->tipo = TipoMovimientoFinanciero::Ingreso->value;
        $this->fecha = '';
        $this->monto = '';
        $this->observacion = '';
    }
}; ?>

<section class="grid gap-6" aria-labelledby="movimientos-heading">
    <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-end">
        <div class="grid gap-2">
            <div class="flex items-center gap-2">
                <flux:heading id="movimientos-heading" size="lg">Movimientos financieros</flux:heading>
                <flux:badge color="zinc" size="sm">CLP</flux:badge>
            </div>
            <flux:text>Ingresos y egresos asociados exclusivamente a esta causa.</flux:text>
        </div>

        @can('create', \App\Models\MovimientoFinanciero::class)
            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal('INGRESO')">Nuevo ingreso</flux:button>
                <flux:button variant="outline" icon="minus" wire:click="openCreateModal('EGRESO')">Nuevo egreso</flux:button>
            </div>
        @endcan
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-sm border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/40">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Total ingresos</p>
            <p class="mt-2 text-2xl font-semibold text-emerald-800 dark:text-emerald-200">{{ $this->formatClp($this->resumenFinanciero['totalIngresos']) }}</p>
        </div>
        <div class="rounded-sm border border-rose-200 bg-rose-50 p-5 dark:border-rose-900 dark:bg-rose-950/40">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300">Total egresos</p>
            <p class="mt-2 text-2xl font-semibold text-rose-800 dark:text-rose-200">{{ $this->formatClp($this->resumenFinanciero['totalEgresos']) }}</p>
        </div>
        <div class="rounded-sm border border-[#006a61]/30 bg-[#006a61]/5 p-5 dark:border-teal-700 dark:bg-teal-950/40">
            <p class="text-xs font-semibold uppercase tracking-wide text-[#006a61] dark:text-teal-300">Saldo</p>
            <p class="mt-2 text-2xl font-semibold {{ $this->resumenFinanciero['saldo'] < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-[#091426] dark:text-slate-100' }}">{{ $this->formatClp($this->resumenFinanciero['saldo']) }}</p>
        </div>
    </div>

    <div class="overflow-x-auto rounded-sm border border-[#c5c6cd] dark:border-slate-700" wire:loading.class="opacity-60" wire:target="save,delete">
        <table class="min-w-full divide-y divide-[#c5c6cd] text-sm dark:divide-slate-700">
            <thead class="bg-[#f3f4fa] text-left text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:bg-slate-800 dark:text-zinc-300">
                <tr>
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Tipo</th>
                    <th class="px-4 py-3 text-right">Monto</th>
                    <th class="px-4 py-3">Observación</th>
                    <th class="px-4 py-3">Registrado por</th>
                    <th class="px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#c5c6cd] bg-white dark:divide-slate-700 dark:bg-slate-900">
                @forelse ($this->movimientos as $movimiento)
                    <tr wire:key="movimiento-{{ $movimiento->id }}">
                        <td class="whitespace-nowrap px-4 py-4 font-medium">{{ $movimiento->fecha?->format('d-m-Y') ?? 'Sin fecha' }}</td>
                        <td class="px-4 py-4">
                            <flux:badge :color="$movimiento->tipo === \App\Enums\TipoMovimientoFinanciero::Ingreso ? 'green' : 'red'" size="sm">{{ $movimiento->tipo->value }}</flux:badge>
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-right font-semibold {{ $movimiento->tipo === \App\Enums\TipoMovimientoFinanciero::Ingreso ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">{{ $this->formatClp($movimiento->monto) }}</td>
                        <td class="max-w-sm whitespace-pre-wrap break-words px-4 py-4 text-zinc-700 dark:text-zinc-300">{{ $movimiento->observacion ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-4 text-zinc-600 dark:text-zinc-400">{{ $movimiento->creador?->name ?? 'Usuario no disponible' }}</td>
                        <td class="px-4 py-4">
                            <div class="flex justify-end gap-1">
                                @can('update', $movimiento)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $movimiento->id }})">Editar</flux:button>
                                @endcan
                                @can('delete', $movimiento)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="requestDelete({{ $movimiento->id }})">Eliminar</flux:button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <flux:heading size="lg">Sin movimientos financieros</flux:heading>
                            <flux:text class="mt-2">Esta causa todavía no registra ingresos ni egresos.</flux:text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->movimientos->hasPages())
        <flux:pagination :paginator="$this->movimientos" scroll-to="#movimientos-heading" />
    @endif

    <flux:modal wire:model="showFormModal" class="md:min-w-xl" scroll="body">
        <form wire:submit="save" class="grid gap-6">
            <div class="grid gap-2">
                <flux:heading size="lg">{{ $editingId === null ? 'Nuevo movimiento financiero' : 'Editar movimiento financiero' }}</flux:heading>
                <flux:text>Los montos se registran en pesos chilenos (CLP), sin centavos.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="tipo" label="Tipo" required>
                    <option value="INGRESO">INGRESO</option>
                    <option value="EGRESO">EGRESO</option>
                </flux:select>
                <flux:input wire:model="fecha" type="date" label="Fecha efectiva" required />
            </div>

            <flux:input wire:model="monto" type="number" label="Monto (CLP)" min="1" max="9999999999999" step="1" inputmode="numeric" required />
            <flux:textarea wire:model="observacion" label="Observación" rows="5" resize="vertical" maxlength="50000" />

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" icon="check" wire:loading.attr="disabled">Guardar movimiento</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="md:max-w-md">
        <div class="grid gap-5">
            <div class="grid gap-2">
                <flux:heading size="lg">Eliminar movimiento financiero</flux:heading>
                <flux:text>El movimiento dejará de afectar los totales, pero permanecerá disponible para recuperación administrativa.</flux:text>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled">Confirmar eliminación</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
