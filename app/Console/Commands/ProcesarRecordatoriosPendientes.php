<?php

namespace App\Console\Commands;

use App\Enums\EstadoRecordatorio;
use App\Models\Recordatorio;
use App\Notifications\RecordatorioPendienteNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recordatorios:procesar')]
#[Description('Envía las notificaciones internas pendientes de los recordatorios')]
class ProcesarRecordatoriosPendientes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();
        $sent = 0;

        Recordatorio::query()
            ->where('estado', EstadoRecordatorio::Pendiente)
            ->whereNull('notificado_at')
            ->where('notificar_en', '<=', $now)
            ->whereHas('usuario', fn ($query) => $query->where('activo', true))
            ->with(['causa:id,numero_causa,nombre', 'usuario:id,name,activo'])
            ->orderBy('id')
            ->chunkById(100, function ($recordatorios) use ($now, &$sent): void {
                foreach ($recordatorios as $recordatorio) {
                    $claimed = Recordatorio::query()
                        ->whereKey($recordatorio->id)
                        ->where('estado', EstadoRecordatorio::Pendiente)
                        ->whereNull('notificado_at')
                        ->update(['notificado_at' => $now]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    $recordatorio->usuario->notify(new RecordatorioPendienteNotification($recordatorio));
                    $sent++;
                }
            });

        $this->info("{$sent} recordatorio(s) notificado(s).");

        return self::SUCCESS;
    }
}
