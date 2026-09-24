<?php

use App\Enums\EstadoRecordatorio;
use App\Enums\Permiso;
use App\Models\Causa;
use App\Models\Recordatorio;
use App\Models\User;
use App\Services\RecordatorioService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public int $causaId;

    #[Locked]
    public ?int $editingId = null;

    public bool $showFormModal = false;

    public bool $showHistoryModal = false;

    public string $titulo = '';

    public string $descripcion = '';

    public string $fecha = '';

    public string $hora = '';

    public string $recordarMinutosAntes = '60';

    public string $userId = '';

    public function mount(Causa $causa): void
    {
        Gate::authorize('view', $causa);
        Gate::authorize('viewAny', Recordatorio::class);
        $this->causaId = $causa->id;
    }

    public function openCreateModal(): void
    {
        $causa = $this->causa();
        Gate::authorize('view', $causa);
        Gate::authorize('create', Recordatorio::class);

        $this->resetForm();
        $this->fecha = now()->toDateString();
        $this->hora = now()->addHour()->format('H:i');
        $this->userId = (string) ($causa->responsable_id ?? auth()->id());
        $this->showFormModal = true;
    }

    public function openHistoryModal(): void
    {
        Gate::authorize('view', $this->causa());
        Gate::authorize('viewAny', Recordatorio::class);

        $this->showHistoryModal = true;
    }

    public function openEditModal(int $recordatorioId): void
    {
        $recordatorio = $this->findRecordatorio($recordatorioId);
        Gate::authorize('update', $recordatorio);

        $this->resetValidation();
        $this->editingId = $recordatorio->id;
        $this->titulo = $recordatorio->titulo;
        $this->descripcion = $recordatorio->descripcion ?? '';
        $this->fecha = $recordatorio->fecha_hora->format('Y-m-d');
        $this->hora = $recordatorio->fecha_hora->format('H:i');
        $this->recordarMinutosAntes = (string) ($recordatorio->recordar_minutos_antes ?? 0);
        $this->userId = (string) $recordatorio->user_id;
        $this->showFormModal = true;
    }

    public function save(RecordatorioService $recordatorioService): void
    {
        $causa = $this->causa();
        Gate::authorize('view', $causa);
        $recordatorio = $this->editingId === null ? null : $this->findRecordatorio($this->editingId);
        Gate::authorize($recordatorio === null ? 'create' : 'update', $recordatorio ?? Recordatorio::class);

        $this->titulo = trim($this->titulo);
        $this->descripcion = trim($this->descripcion);

        $validated = $this->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:50000'],
            'fecha' => ['required', 'date'],
            'hora' => ['required', 'date_format:H:i'],
            'recordarMinutosAntes' => ['required', 'integer', Rule::in([0, 15, 30, 60, 180, 1440, 2880, 4320, 10080])],
            'userId' => ['required', 'integer', Rule::exists(User::class, 'id')->where('activo', true)],
        ], attributes: [
            'titulo' => 'título',
            'descripcion' => 'descripción',
            'fecha' => 'fecha',
            'hora' => 'hora',
            'recordarMinutosAntes' => 'anticipación',
            'userId' => 'destinatario',
        ]);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $attributes = [
            'titulo' => $validated['titulo'],
            'descripcion' => blank($validated['descripcion']) ? null : $validated['descripcion'],
            'fecha_hora' => CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['fecha'].' '.$validated['hora'], config('app.timezone')),
            'recordar_minutos_antes' => (int) $validated['recordarMinutosAntes'],
            'user_id' => (int) $validated['userId'],
        ];

        if ($recordatorio === null) {
            $recordatorioService->create($causa, $actor, $attributes);
            $message = 'Recordatorio creado correctamente.';
        } else {
            $recordatorioService->update($recordatorio, $actor, $attributes);
            $message = 'Recordatorio actualizado correctamente.';
        }

        $this->showFormModal = false;
        $this->resetForm();
        unset($this->recordatorios);
        Flux::toast(variant: 'success', text: $message);
    }

    public function complete(int $recordatorioId, RecordatorioService $recordatorioService): void
    {
        $recordatorio = $this->findRecordatorio($recordatorioId);
        Gate::authorize('update', $recordatorio);
        abort_unless($recordatorio->estado === EstadoRecordatorio::Pendiente, 422);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $recordatorioService->complete($recordatorio, $actor);
        unset($this->recordatorios);
        Flux::toast(variant: 'success', text: 'Recordatorio marcado como completado.');
    }

    public function cancel(int $recordatorioId, RecordatorioService $recordatorioService): void
    {
        $recordatorio = $this->findRecordatorio($recordatorioId);
        Gate::authorize('cancel', $recordatorio);
        abort_unless($recordatorio->estado === EstadoRecordatorio::Pendiente, 422);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $recordatorioService->cancel($recordatorio, $actor);
        unset($this->recordatorios);
        Flux::toast(variant: 'success', text: 'Recordatorio cancelado.');
    }

    /** @return Collection<int, Recordatorio> */
    #[Computed]
    public function recordatorios(): Collection
    {
        return Recordatorio::query()
            ->select(['id', 'causa_id', 'user_id', 'titulo', 'descripcion', 'fecha_hora', 'recordar_minutos_antes', 'estado', 'created_at'])
            ->with('usuario:id,name,codigo')
            ->where('causa_id', $this->causaId)
            ->where('estado', EstadoRecordatorio::Pendiente)
            ->orderBy('fecha_hora')
            ->get();
    }

    /** @return Collection<int, Recordatorio> */
    #[Computed]
    public function recordatoriosHistoricos(): Collection
    {
        if (! $this->showHistoryModal) {
            return new Collection;
        }

        return Recordatorio::query()
            ->select(['id', 'causa_id', 'user_id', 'titulo', 'descripcion', 'fecha_hora', 'recordar_minutos_antes', 'estado'])
            ->with('usuario:id,name,codigo')
            ->where('causa_id', $this->causaId)
            ->whereIn('estado', [EstadoRecordatorio::Completado, EstadoRecordatorio::Cancelado])
            ->orderByDesc('fecha_hora')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function usuariosAsignables(): Collection
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        if (! $actor->can(Permiso::RecordatoriosAsignar->value)) {
            return User::query()->whereKey($this->causa()->responsable_id ?? $actor->id)->get();
        }

        return User::query()->where('activo', true)->orderBy('name')->get(['id', 'name', 'codigo']);
    }

    private function causa(): Causa
    {
        return Causa::query()->findOrFail($this->causaId);
    }

    private function findRecordatorio(int $recordatorioId): Recordatorio
    {
        return Recordatorio::query()
            ->with('causa:id,responsable_id')
            ->where('causa_id', $this->causaId)
            ->findOrFail($recordatorioId);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->titulo = '';
        $this->descripcion = '';
        $this->fecha = '';
        $this->hora = '';
        $this->recordarMinutosAntes = '60';
        $this->userId = '';
    }
}; ?>

<section class="grid gap-5" aria-labelledby="recordatorios-heading">
    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div class="grid gap-2">
            <div class="flex items-center gap-2">
                <flux:heading id="recordatorios-heading" size="lg">Recordatorios</flux:heading>
                <flux:badge color="amber" size="sm">{{ $this->recordatorios->count() }} pendientes</flux:badge>
            </div>
            <flux:text>Agenda interna de plazos y tareas asociadas a esta causa.</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" icon="clock" wire:click="openHistoryModal">Ver historial</flux:button>
            @can('create', \App\Models\Recordatorio::class)
                <flux:button variant="primary" icon="bell-alert" wire:click="openCreateModal">Nuevo recordatorio</flux:button>
            @endcan
        </div>
    </div>

    <div class="grid gap-3" wire:loading.class="opacity-60" wire:target="save,complete,cancel">
        @forelse ($this->recordatorios as $recordatorio)
            <article
                wire:key="recordatorio-{{ $recordatorio->id }}"
                x-data="{
                    fechaHora: @js($recordatorio->fecha_hora->toIso8601String()),
                    horaServidor: @js(now()->toIso8601String()),
                    instanteCliente: Date.now(),
                    vencido: @js($recordatorio->estaVencido()),
                    temporizador: null,
                    init() {
                        this.actualizarVencimiento();
                        this.temporizador = window.setInterval(() => this.actualizarVencimiento(), 1000);
                    },
                    destroy() {
                        window.clearInterval(this.temporizador);
                    },
                    actualizarVencimiento() {
                        const ahoraServidor = new Date(this.horaServidor).getTime() + (Date.now() - this.instanteCliente);
                        this.vencido = new Date(this.fechaHora).getTime() <= ahoraServidor;
                    },
                }"
                class="grid gap-3 rounded-sm border border-[#c5c6cd] bg-white p-4 dark:border-slate-700 dark:bg-slate-900 sm:grid-cols-[1fr_auto] sm:items-start"
            >
                <div class="grid gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $recordatorio->titulo }}</flux:heading>
                        <span x-show="! vencido"><flux:badge color="amber" size="sm">PENDIENTE</flux:badge></span>
                        <span x-show="vencido"><flux:badge color="red" size="sm">VENCIDO</flux:badge></span>
                    </div>
                    @if (filled($recordatorio->descripcion))
                        <p class="whitespace-pre-wrap break-words text-sm text-zinc-700 dark:text-zinc-300">{{ $recordatorio->descripcion }}</p>
                    @endif
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ $recordatorio->fecha_hora->format('d-m-Y H:i') }}</span>
                        <span>Avísame {{ $recordatorio->recordar_minutos_antes === 0 ? 'a la hora' : $recordatorio->recordar_minutos_antes.' min antes' }}</span>
                        <span>Asignado a: {{ $recordatorio->usuario?->etiquetaResponsable() ?? 'Usuario no disponible' }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1 sm:justify-end">
                    @can('update', $recordatorio)
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $recordatorio->id }})">Editar</flux:button>
                        <flux:button size="sm" variant="ghost" icon="check" wire:click="complete({{ $recordatorio->id }})">Completar</flux:button>
                    @endcan
                    @can('cancel', $recordatorio)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancel({{ $recordatorio->id }})">Cancelar</flux:button>
                    @endcan
                </div>
            </article>
        @empty
            <div class="rounded-sm border border-dashed border-[#c5c6cd] p-8 text-center dark:border-slate-700">
                <flux:heading size="sm">Sin recordatorios pendientes</flux:heading>
                <flux:text class="mt-1">Crea un recordatorio para controlar un plazo o tarea de esta causa.</flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal wire:model="showHistoryModal" class="md:min-w-2xl" scroll="body">
        <div class="grid gap-5">
            <div class="grid gap-1">
                <flux:heading size="lg">Historial de recordatorios</flux:heading>
                <flux:text>Recordatorios completados o cancelados de esta causa. Se muestran los últimos 100 registros.</flux:text>
            </div>

            <div class="grid gap-3">
                @forelse ($this->recordatoriosHistoricos as $recordatorio)
                    <article wire:key="recordatorio-historico-{{ $recordatorio->id }}" class="grid gap-2 rounded-sm border border-[#c5c6cd] p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="sm">{{ $recordatorio->titulo }}</flux:heading>
                            @if ($recordatorio->estado === \App\Enums\EstadoRecordatorio::Completado)
                                <flux:badge color="green" size="sm">COMPLETADO</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">CANCELADO</flux:badge>
                            @endif
                        </div>
                        @if (filled($recordatorio->descripcion))
                            <p class="whitespace-pre-wrap break-words text-sm text-zinc-700 dark:text-zinc-300">{{ $recordatorio->descripcion }}</p>
                        @endif
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $recordatorio->fecha_hora->format('d-m-Y H:i') }}</span>
                            <span>Asignado a: {{ $recordatorio->usuario?->etiquetaResponsable() ?? 'Usuario no disponible' }}</span>
                        </div>
                    </article>
                @empty
                    <div class="rounded-sm border border-dashed border-[#c5c6cd] p-8 text-center dark:border-slate-700">
                        <flux:heading size="sm">Sin historial de recordatorios</flux:heading>
                        <flux:text class="mt-1">Los recordatorios completados o cancelados aparecerán aquí.</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="ghost" type="button">Cerrar</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showFormModal" class="md:min-w-xl" scroll="body">
        <form wire:submit="save" class="grid gap-5">
            <div class="grid gap-2">
                <flux:heading size="lg">{{ $editingId === null ? 'Nuevo recordatorio' : 'Editar recordatorio' }}</flux:heading>
                <flux:text>La notificación se mostrará sólo al usuario asignado.</flux:text>
            </div>
            <flux:input wire:model="titulo" label="Título" maxlength="255" required />
            <flux:textarea wire:model="descripcion" label="Descripción" rows="4" resize="vertical" maxlength="50000" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="fecha" type="date" label="Fecha" required />
                <flux:input wire:model="hora" type="time" label="Hora" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="recordarMinutosAntes" label="Avisar con anticipación" required>
                    <option value="0">A la hora programada</option><option value="15">15 minutos antes</option><option value="30">30 minutos antes</option><option value="60">1 hora antes</option><option value="180">3 horas antes</option><option value="1440">1 día antes</option><option value="2880">2 días antes</option><option value="4320">3 días antes</option><option value="10080">1 semana antes</option>
                </flux:select>
                <flux:select wire:model="userId" label="Destinatario" required>
                    @foreach ($this->usuariosAsignables as $usuario)
                        <option value="{{ $usuario->id }}">{{ $usuario->etiquetaResponsable() }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" icon="check" wire:loading.attr="disabled">Guardar recordatorio</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
