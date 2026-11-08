<?php

namespace App\Jobs;

use App\Models\PjudProgramacion;
use App\Services\GoogleCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crea el evento "⚖️ Causa en tabla" en el calendario Google institucional,
 * el día de la vista, para una causa detectada en la programación de salas.
 */
class NotificarProgramacionPjudGoogleCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $programacionId) {}

    public function handle(GoogleCalendarService $googleCalendarService): void
    {
        $programacion = PjudProgramacion::query()
            ->with(['causa' => fn ($query) => $query->select(['id', 'nombre', 'numero_causa', 'juzgado_id', 'responsable_id'])
                ->with(['responsable:id,name,email', 'juzgado:id,nombre'])])
            ->find($this->programacionId);

        if ($programacion === null || $programacion->notificado_at !== null) {
            return; // Ya notificada: nunca se duplica el aviso.
        }

        $eventId = $googleCalendarService->crearEventoProgramacionPjud($programacion);

        if ($eventId === null) {
            Log::warning('Causa en tabla detectada, pero Google Calendar no está configurado.', ['programacion_id' => $programacion->id]);

            return;
        }

        $programacion->forceFill(['google_calendar_event_id' => $eventId, 'notificado_at' => now()])->save();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pjud-programacion-calendar-{$this->programacionId}"))->releaseAfter(5)->expireAfter(60)];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No fue posible notificar la causa en tabla en Google Calendar.', [
            'programacion_id' => $this->programacionId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
