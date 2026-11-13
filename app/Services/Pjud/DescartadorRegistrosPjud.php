<?php

namespace App\Services\Pjud;

use App\Enums\AccionAuditoria;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descarta un registro del monitoreo PJUD que resultó ser un falso positivo.
 *
 * El registro no se borra: queda con soft delete, el usuario y el motivo, para
 * que el escaneo lo reconozca por su huella y no lo vuelva a crear ni a avisar.
 * También se elimina su evento de Google Calendar y se deja constancia en la
 * bitácora de auditoría con todos los datos del registro.
 */
class DescartadorRegistrosPjud
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @return bool true si también se eliminó (o no existía) el evento de Google Calendar.
     */
    public function descartar(PjudMovimiento|PjudProgramacion $registro, User $usuario, string $motivo): bool
    {
        $eventoEliminado = $this->eliminarEventoCalendario($registro);

        DB::transaction(function () use ($registro, $usuario, $motivo, $eventoEliminado): void {
            $registro->forceFill([
                'descartado_por' => $usuario->id,
                'motivo_descarte' => trim($motivo),
                'google_calendar_event_id' => $eventoEliminado ? null : $registro->google_calendar_event_id,
            ])->saveQuietly();

            $registro->delete();

            $this->auditoria->record($registro, AccionAuditoria::MonitoreoDescartado, $registro->getAttributes(), null);
        });

        return $eventoEliminado;
    }

    private function eliminarEventoCalendario(PjudMovimiento|PjudProgramacion $registro): bool
    {
        if (blank($registro->google_calendar_event_id)) {
            return true;
        }

        try {
            $this->googleCalendar->eliminarEvento($registro->google_calendar_event_id);

            return true;
        } catch (Throwable $exception) {
            Log::warning('No fue posible eliminar el evento de Google Calendar del registro PJUD descartado.', [
                'modelo' => $registro::class,
                'id' => $registro->id,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
