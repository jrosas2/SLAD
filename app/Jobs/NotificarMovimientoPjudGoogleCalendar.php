<?php

namespace App\Jobs;

use App\Models\PjudMovimiento;
use App\Services\GoogleCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crea el evento "🚩 Actividad P. Judicial" en el calendario Google
 * institucional para un movimiento detectado en el Estado Diario.
 */
class NotificarMovimientoPjudGoogleCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $movimientoId) {}

    public function handle(GoogleCalendarService $googleCalendarService): void
    {
        $movimiento = PjudMovimiento::query()
            ->with(['causa' => fn ($query) => $query->select(['id', 'nombre', 'numero_causa', 'juzgado_id', 'responsable_id'])
                ->with(['responsable:id,name,email', 'juzgado:id,nombre'])])
            ->find($this->movimientoId);

        if ($movimiento === null || $movimiento->notificado_at !== null) {
            return; // Ya notificado: nunca se duplica el aviso.
        }

        $eventId = $googleCalendarService->crearEventoActividadJudicial($movimiento);

        if ($eventId === null) {
            Log::warning('Movimiento PJUD detectado, pero Google Calendar no está configurado.', ['movimiento_id' => $movimiento->id]);

            return;
        }

        $movimiento->forceFill(['google_calendar_event_id' => $eventId, 'notificado_at' => now()])->save();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pjud-movimiento-calendar-{$this->movimientoId}"))->releaseAfter(5)->expireAfter(60)];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No fue posible notificar el movimiento PJUD en Google Calendar.', [
            'movimiento_id' => $this->movimientoId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
