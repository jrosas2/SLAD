<?php

use App\Enums\EstadoRecordatorio;
use App\Models\Recordatorio;
use App\Notifications\RecordatorioPendienteNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /** @return Collection<int, \Illuminate\Notifications\DatabaseNotification> */
    #[Computed]
    public function notifications(): Collection
    {
        return auth()->user()
            ->notifications()
            ->where('type', RecordatorioPendienteNotification::class)
            ->latest()
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()
            ->unreadNotifications()
            ->where('type', RecordatorioPendienteNotification::class)
            ->count();
    }

    /** @return Collection<int, Recordatorio> */
    #[Computed]
    public function remindersAwaitingAlert(): Collection
    {
        return Recordatorio::query()
            ->select(['id', 'causa_id', 'titulo', 'fecha_hora', 'notificar_en'])
            ->with('causa:id,numero_causa,nombre')
            ->whereHas('causa')
            ->where('user_id', auth()->id())
            ->where('estado', EstadoRecordatorio::Pendiente)
            ->whereNull('notificado_at')
            ->orderBy('notificar_en')
            ->limit(8)
            ->get();
    }

    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()
            ->notifications()
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            throw new ModelNotFoundException;
        }

        $notification->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    public function markAllAsRead(): void
    {
        auth()->user()
            ->unreadNotifications()
            ->where('type', RecordatorioPendienteNotification::class)
            ->update(['read_at' => now()]);

        unset($this->notifications, $this->unreadCount);
    }
}; ?>

@php
    $notifications = $this->notifications;
    $recordatoriosPendientes = $this->remindersAwaitingAlert
        ->map(fn (Recordatorio $recordatorio): array => [
            'id' => $recordatorio->id,
            'titulo' => $recordatorio->titulo,
            'causaNumero' => $recordatorio->causa->numero_causa ?? 'Causa',
            'fechaHora' => $recordatorio->fecha_hora->toIso8601String(),
            'notificarEn' => $recordatorio->notificar_en->toIso8601String(),
            'url' => route('causas.show', $recordatorio->causa),
        ])
        ->values();
    $recordatoriosNotificados = $notifications
        ->map(fn ($notification) => $notification->data['recordatorio_id'] ?? null)
        ->filter()
        ->values();
@endphp

<div
    wire:poll.30s
    class="relative"
    x-data="{
        horaServidor: @js(now()->toIso8601String()),
        instanteCliente: Date.now(),
        notificacionesNoLeidas: @js($this->unreadCount),
        recordatorios: @js($recordatoriosPendientes),
        recordatoriosNotificados: @js($recordatoriosNotificados),
        avisosInmediatos: [],
        temporizador: null,
        init() {
            this.actualizarAvisos();
            this.temporizador = window.setInterval(() => this.actualizarAvisos(), 1000);
        },
        destroy() {
            window.clearInterval(this.temporizador);
        },
        actualizarAvisos() {
            const ahoraServidor = new Date(this.horaServidor).getTime() + (Date.now() - this.instanteCliente);

            this.avisosInmediatos = this.recordatorios.filter((recordatorio) => {
                return new Date(recordatorio.notificarEn).getTime() <= ahoraServidor
                    && ! this.recordatoriosNotificados.includes(recordatorio.id);
            });
        },
        contador() {
            return this.notificacionesNoLeidas + this.avisosInmediatos.length;
        },
    }"
>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" icon="bell" square aria-label="Notificaciones">
            <span x-cloak x-show="contador() > 0" x-text="Math.min(contador(), 99)" class="absolute -right-1 -top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold leading-4 text-white"></span>
        </flux:button>
        <flux:menu class="w-80">
            <div class="flex items-center justify-between gap-3 px-2 py-2">
                <flux:heading size="sm">Recordatorios</flux:heading>
                @if ($this->unreadCount > 0)
                    <flux:button size="sm" variant="ghost" wire:click="markAllAsRead">Marcar todo leído</flux:button>
                @endif
            </div>
            <flux:menu.separator />
            <div x-cloak x-show="avisosInmediatos.length > 0">
                <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Por atender ahora</div>
                <template x-for="recordatorio in avisosInmediatos" :key="`recordatorio-inmediato-${recordatorio.id}`">
                    <a :href="recordatorio.url" class="block px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <div class="grid gap-1 whitespace-normal font-semibold">
                            <span x-text="recordatorio.titulo"></span>
                            <span class="text-xs font-normal text-zinc-500" x-text="`${recordatorio.causaNumero} · Aviso programado`"></span>
                        </div>
                    </a>
                </template>
                <flux:menu.separator />
            </div>
            @foreach ($notifications as $notification)
                <flux:menu.item wire:key="notification-{{ $notification->id }}" :href="$notification->data['url'] ?? route('dashboard')" wire:click="markAsRead('{{ $notification->id }}')" wire:navigate>
                    <div class="grid gap-1 whitespace-normal {{ $notification->read_at === null ? 'font-semibold' : '' }}">
                        <span>{{ $notification->data['mensaje'] ?? 'Recordatorio pendiente' }}</span>
                        <span class="text-xs font-normal text-zinc-500">{{ $notification->data['causa_numero'] ?? 'Causa' }} · {{ $notification->created_at->format('d-m H:i') }}</span>
                    </div>
                </flux:menu.item>
            @endforeach
            @if ($notifications->isEmpty())
                <div x-cloak x-show="avisosInmediatos.length === 0" class="px-3 py-6 text-center text-sm text-zinc-500">No tienes recordatorios notificados.</div>
            @endif
        </flux:menu>
    </flux:dropdown>
</div>
