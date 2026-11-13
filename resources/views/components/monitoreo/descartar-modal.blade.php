@props(['registro'])

<flux:modal wire:model="showDescarte" wire:close="cancelarDescarte" class="md:min-w-xl">
    @if ($registro)
        <form wire:submit="descartar" class="grid gap-5">
            <div class="grid gap-1">
                <flux:heading size="lg">¿Eliminar este registro del monitoreo?</flux:heading>
                <flux:text>Use esta opción sólo si el monitoreo automático generó un falso positivo. El registro dejará de mostrarse en Monitoreo, en la causa y en la impresión, y el escaneo no lo volverá a avisar.</flux:text>
            </div>

            <dl class="grid gap-3 rounded-lg border border-zinc-200 p-4 text-sm sm:grid-cols-2 dark:border-zinc-700">
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Registro</dt>
                    <dd>{{ $registro instanceof \App\Models\PjudMovimiento ? 'Movimiento del Estado Diario' : 'Agendamiento en sala' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Causa</dt>
                    <dd class="break-words">{{ $registro->causa?->numero_causa ?? $registro->causa?->nombre ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Ingreso PJUD</dt>
                    <dd class="break-words">{{ $registro->numero_ingreso }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Fecha</dt>
                    <dd>{{ ($registro instanceof \App\Models\PjudMovimiento ? $registro->fecha_estado_diario : $registro->fecha_vista)?->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Tribunal</dt>
                    <dd class="break-words">{{ $registro->tribunal_nombre }}@if ($registro instanceof \App\Models\PjudProgramacion) · {{ $registro->sala }}@endif</dd>
                </div>
            </dl>

            <flux:textarea wire:model="motivoDescarte" label="Motivo de la eliminación (obligatorio)" placeholder="Ej.: no corresponde a esta causa, es otra con el mismo número." rows="3" />

            <flux:text class="text-xs">
                @if (filled($registro->google_calendar_event_id))
                    También se eliminará su evento en Google Calendar.
                @endif
                La eliminación, el motivo y todos los datos del registro quedarán en la bitácora de auditoría.
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelarDescarte">Cancelar</flux:button>
                <flux:button type="submit" variant="danger" icon="trash" wire:loading.attr="disabled" wire:target="descartar">Eliminar</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
