<?php

namespace App\Console\Commands;

use App\Models\Causa;
use App\Models\PjudProgramacion;
use App\Models\TribunalPjud;
use App\Services\GoogleCalendarService;
use App\Support\Pjud\RegistroMonitoreoPjud;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('pjud:status-programacion
    {momento : inicio o cierre}')]
#[Description('Avisa en Google Calendar el inicio o el cierre (con resumen) del monitoreo de programación de salas')]
class StatusProgramacionPjud extends Command
{
    public function handle(GoogleCalendarService $calendar): int
    {
        $momento = (string) $this->argument('momento');

        if (! in_array($momento, ['inicio', 'cierre'], true)) {
            $this->error('El momento debe ser "inicio" o "cierre".');

            return self::FAILURE;
        }

        if (! config('pjud.programacion.enabled')) {
            $this->warn('El monitoreo de programación PJUD está desactivado (PJUD_PROGRAMACION_ENABLED=false). No se envía status.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now()->startOfDay();
        [$titulo, $lineas] = $momento === 'inicio' ? $this->inicio() : $this->cierre($hoy);
        $descripcion = implode("\n", $lineas);

        $this->info($titulo);
        $this->line($descripcion);

        try {
            $eventId = $calendar->crearEventoStatusMonitoreoPjud($titulo, $descripcion);
        } catch (Throwable $exception) {
            Log::error('No fue posible enviar el status de la programación PJUD.', ['momento' => $momento, 'exception' => $exception->getMessage()]);
            $this->error('No fue posible crear el evento en Google Calendar: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($eventId === null) {
            $this->warn('Google Calendar no está configurado: el status no se envió.');

            return self::SUCCESS;
        }

        $this->info("Evento creado: {$eventId}");

        return self::SUCCESS;
    }

    /** @return array{string, list<string>} */
    private function inicio(): array
    {
        $cortes = RevisarProgramacionPjud::cortesEnMonitoreo();
        $estados = (array) config('pjud.programacion.estados_causa_consultables', ['VIGENTE']);

        return ['✅ Programación PJUD iniciada', [
            'Fecha: '.now()->format('d-m-Y'),
            'Cortes en monitoreo: '.($cortes->isEmpty() ? 'ninguna' : $cortes->pluck('nombre')->implode(', ')),
            'Causas monitoreadas: '.Causa::query()->consultablesPjud($estados)->whereHas('juzgado', fn ($q) => $q->whereIn('tribunal_pjud_codigo', $cortes->pluck('codigo')))->count(),
            sprintf('Horario: %s, cada %d minutos', RevisarProgramacionPjud::rangoHorario(), (int) config('pjud.programacion.horario.frecuencia_minutos', 60)),
            'Avisos pendientes de vistas próximas: '.PjudProgramacion::query()->whereNull('notificado_at')->whereDate('fecha_vista', '>=', now()->toDateString())->count(),
        ]];
    }

    /** @return array{string, list<string>} */
    private function cierre(CarbonImmutable $hoy): array
    {
        $registro = RegistroMonitoreoPjud::delDia($hoy->toDateString(), RegistroMonitoreoPjud::PROGRAMACION);
        $detectadas = PjudProgramacion::query()->whereBetween('created_at', [$hoy, $hoy->endOfDay()]);
        $total = (clone $detectadas)->count();
        $pendientes = (clone $detectadas)->whereNull('notificado_at')->count();
        $primera = (clone $detectadas)->min('detectada_en');

        $lineas = [
            'Fecha: '.$hoy->format('d-m-Y'),
            'Revisiones ejecutadas: '.$registro['revisiones'],
            'Consultas a Cortes correctas: '.$registro['consultas_ok'],
            'Consultas con error: '.$registro['consultas_error'],
            'Causas en tabla detectadas: '.$total,
            'Avisos enviados: '.($total - $pendientes),
            'Avisos pendientes: '.$pendientes,
        ];

        if ($primera !== null) {
            $lineas[] = 'Primera detección del día: '.CarbonImmutable::parse($primera)->format('H:i');
        }

        if ($registro['tribunales_error'] !== []) {
            $lineas[] = 'Cortes con error: '.implode(', ', TribunalPjud::query()->whereIn('codigo', $registro['tribunales_error'])->pluck('nombre')->all());
        }

        if ($registro['revisiones'] === 0) {
            $lineas[] = '⚠️ No se ejecutó ninguna revisión hoy: revise el cron del scheduler y el worker (slad-queue).';
        }

        $conProblemas = $registro['revisiones'] === 0 || $registro['consultas_error'] > 0 || $pendientes > 0;

        return [$conProblemas ? '⚠️ Programación PJUD finalizada con observaciones' : '🏁 Programación PJUD finalizada', $lineas];
    }

    public static function horaInicio(): string
    {
        return sprintf('%02d:00', (int) config('pjud.programacion.horario.hora_inicio', 14));
    }

    public static function horaCierre(): string
    {
        $minutos = max(0, min(59, (int) config('pjud.programacion.notificacion.status_minutos_cierre', 15)));

        return sprintf('%02d:%02d', (int) config('pjud.programacion.horario.hora_fin', 20), $minutos);
    }
}
