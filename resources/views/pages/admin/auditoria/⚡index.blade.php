<?php

use App\Enums\AccionAuditoria;
use App\Enums\Permiso;
use App\Models\Auditoria;
use App\Models\Causa;
use App\Models\Accion;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bitácora de auditoría')] class extends Component {
    use WithPagination;

    public string $usuarioId = '';
    public string $causaSearch = '';
    public string $accion = '';
    public string $modelo = '';
    public string $desde = '';
    public string $hasta = '';

    #[Locked]
    public ?int $detalleId = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permiso::AuditoriaVer->value), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['usuarioId', 'causaSearch', 'accion', 'modelo', 'desde', 'hasta'], true)) {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<Auditoria> */
    #[Computed]
    public function registros(): LengthAwarePaginator
    {
        return Auditoria::query()
            ->with(['usuario:id,name', 'causa:id,nombre,numero_causa'])
            ->when($this->usuarioId !== '', fn (Builder $query) => $query->where('user_id', $this->usuarioId))
            ->when($this->accion !== '', fn (Builder $query) => $query->where('accion', $this->accion))
            ->when($this->modelo !== '', fn (Builder $query) => $query->where('modelo', $this->modelo))
            ->when($this->desde !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->desde))
            ->when($this->hasta !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->hasta))
            ->when(trim($this->causaSearch) !== '', function (Builder $query): void {
                $search = '%'.trim($this->causaSearch).'%';
                $query->whereHas('causa', fn (Builder $causa) => $causa
                    ->where('nombre', 'like', $search)
                    ->orWhere('numero_causa', 'like', $search));
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(15);
    }

    /** @return list<User> */
    #[Computed]
    public function usuarios(): array
    {
        return User::query()->select(['id', 'name'])->orderBy('name')->get()->all();
    }

    /** @return list<string> */
    #[Computed]
    public function modelos(): array
    {
        return Auditoria::query()->select('modelo')->distinct()->orderBy('modelo')->pluck('modelo')->all();
    }

    public function verDetalle(int $id): void
    {
        $this->detalleId = Auditoria::query()->whereKey($id)->value('id');
        abort_if($this->detalleId === null, 404);
        $this->showDetail = true;
    }

    #[Computed]
    public function detalle(): ?Auditoria
    {
        return $this->detalleId === null ? null : Auditoria::query()
            ->with(['usuario:id,name', 'causa:id,nombre,numero_causa'])
            ->find($this->detalleId);
    }

    public function etiquetaModelo(string $modelo): string
    {
        return Str::headline(class_basename($modelo));
    }

    public function etiquetaCampo(string $campo): string
    {
        return match ($campo) {
            'responsable_id' => 'Responsable',
            'estado_causa_id' => 'Estado de causa',
            'estado_procesal_id' => 'Estado procesal',
            'monto_demandado' => 'Monto demandado',
            default => Str::headline($campo),
        };
    }

    public function valorLegible(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if (str_ends_with($campo, '_id') && is_numeric($valor)) {
            $modelo = match ($campo) {
                'responsable_id' => User::class,
                'estado_causa_id' => EstadoCausa::class,
                'estado_procesal_id' => EstadoProcesal::class,
                'accion_id' => Accion::class,
                'direccion_id' => Direccion::class,
                'juzgado_id' => Juzgado::class,
                'materia_id' => Materia::class,
                'submateria_id' => Submateria::class,
                default => null,
            };

            if ($modelo !== null) {
                $column = $modelo === User::class ? 'name' : 'nombre';
                $nombre = $modelo::query()->whereKey($valor)->value($column);

                return $nombre === null ? '#'.$valor : $nombre.' (#'.$valor.')';
            }
        }

        return is_bool($valor) ? ($valor ? 'Sí' : 'No') : (string) $valor;
    }

    public function limpiarFiltros(): void
    {
        $this->reset('usuarioId', 'causaSearch', 'accion', 'modelo', 'desde', 'hasta');
        $this->resetPage();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end dark:border-zinc-700">
        <div class="grid gap-2"><flux:badge color="blue" size="sm" class="w-fit">Administración</flux:badge><flux:heading size="xl" level="1">Bitácora de auditoría</flux:heading><flux:text>Consulta quién realizó cada cambio y conserva su trazabilidad.</flux:text></div>
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <flux:select wire:model.live="usuarioId" label="Usuario"><option value="">Todos los usuarios</option>@foreach ($this->usuarios as $usuario)<option value="{{ $usuario->id }}">{{ $usuario->name }}</option>@endforeach</flux:select>
            <flux:input wire:model.live.debounce.300ms="causaSearch" label="Causa" placeholder="ROL o nombre de causa" />
            <flux:select wire:model.live="accion" label="Acción"><option value="">Todas</option>@foreach (AccionAuditoria::cases() as $item)<option value="{{ $item->value }}">{{ $item->value }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="modelo" label="Módulo"><option value="">Todos</option>@foreach ($this->modelos as $item)<option value="{{ $item }}">{{ $this->etiquetaModelo($item) }}</option>@endforeach</flux:select>
            <flux:input wire:model.live="desde" label="Desde" type="date" />
            <flux:input wire:model.live="hasta" label="Hasta" type="date" />
        </div>
        <flux:button variant="ghost" icon="x-mark" wire:click="limpiarFiltros" class="w-fit">Limpiar filtros</flux:button>
    </flux:card>

    <flux:card>
        <flux:table :paginate="$this->registros">
            <flux:table.columns><flux:table.column>Fecha</flux:table.column><flux:table.column>Usuario</flux:table.column><flux:table.column>Acción</flux:table.column><flux:table.column>Módulo</flux:table.column><flux:table.column>Registro / causa</flux:table.column><flux:table.column align="end">Detalle</flux:table.column></flux:table.columns>
            <flux:table.rows>
                @forelse ($this->registros as $registro)
                    <flux:table.row :key="$registro->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $registro->created_at?->format('d/m/Y H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $registro->usuario?->name ?? 'Sistema' }}</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$registro->accion === AccionAuditoria::Eliminado ? 'red' : ($registro->accion === AccionAuditoria::Creado ? 'green' : 'blue')" size="sm">{{ $registro->accion->value }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $this->etiquetaModelo($registro->modelo) }}</flux:table.cell>
                        <flux:table.cell><span>#{{ $registro->modelo_id }}</span>@if ($registro->causa)<br><span class="text-xs text-zinc-500">{{ $registro->causa->numero_causa ?? $registro->causa->nombre }}</span>@endif</flux:table.cell>
                        <flux:table.cell align="end"><flux:button size="sm" variant="ghost" icon="eye" wire:click="verDetalle({{ $registro->id }})">Ver</flux:button></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="6" class="py-10 text-center">No hay eventos de auditoría para los filtros seleccionados.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showDetail" class="md:min-w-2xl">
        @if ($this->detalle)
            <div class="grid gap-5"><div><flux:heading size="lg">Detalle de auditoría</flux:heading><flux:text>{{ $this->detalle->created_at?->format('d/m/Y H:i:s') }}</flux:text></div>
                <dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-xs font-semibold uppercase text-zinc-500">Usuario</dt><dd>{{ $this->detalle->usuario?->name ?? 'Sistema' }}</dd></div><div><dt class="text-xs font-semibold uppercase text-zinc-500">Acción</dt><dd>{{ $this->detalle->accion->value }}</dd></div><div><dt class="text-xs font-semibold uppercase text-zinc-500">Entidad</dt><dd>{{ $this->etiquetaModelo($this->detalle->modelo) }} #{{ $this->detalle->modelo_id }}</dd></div><div><dt class="text-xs font-semibold uppercase text-zinc-500">Causa relacionada</dt><dd>{{ $this->detalle->causa?->numero_causa ?? $this->detalle->causa?->nombre ?? '—' }}</dd></div><div><dt class="text-xs font-semibold uppercase text-zinc-500">IP</dt><dd>{{ $this->detalle->ip_address ?? '—' }}</dd></div><div><dt class="text-xs font-semibold uppercase text-zinc-500">User agent</dt><dd class="break-words">{{ $this->detalle->user_agent ?? '—' }}</dd></div></dl>
                @php($campos = array_unique(array_merge(array_keys($this->detalle->valores_anteriores ?? []), array_keys($this->detalle->valores_nuevos ?? []))))
                <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="px-2 py-2">Campo</th><th class="px-2 py-2">Anterior</th><th class="px-2 py-2">Nuevo</th></tr></thead><tbody>@foreach ($campos as $campo)<tr class="border-b"><td class="px-2 py-2 font-medium">{{ $this->etiquetaCampo($campo) }}</td><td class="px-2 py-2">{{ $this->valorLegible($campo, $this->detalle->valores_anteriores[$campo] ?? null) }}</td><td class="px-2 py-2">{{ $this->valorLegible($campo, $this->detalle->valores_nuevos[$campo] ?? null) }}</td></tr>@endforeach</tbody></table></div>
                <div class="flex justify-end"><flux:modal.close><flux:button variant="ghost">Cerrar</flux:button></flux:modal.close></div>
            </div>
        @endif
    </flux:modal>
</div>
