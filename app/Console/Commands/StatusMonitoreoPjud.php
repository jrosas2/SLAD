<?php

namespace App\Console\Commands;

use App\Models\Causa;
use App\Models\PjudMovimiento;
use App\Models\TribunalPjud;
use App\Services\GoogleCalendarService;
use App\Support\Pjud\RegistroMonitoreoPjud;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('pjud:status-monitoreo
    {momento : inicio o cierre}')]
#[Description('Avisa en Google Calendar el inicio o el cierre (con resumen) del monitoreo diario del PJUD')]
class StatusMonitoreoPjud extends Command
{
    public function handle(GoogleCalendarService $calendar): int
    {
        $momento = (string) $this->argument('momento');

        if (! in_array($momento, ['inicio', 'cierre'], true)) {
            $this->error('El momento debe ser "inicio" o "cierre".');

            return self::FAILURE;
        }

        if (! config('pjud.enabled')) {
            $this->warn('El monitoreo PJUD está desactivado (PJUD_ENABLED=false). No se envía status.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now()->startOfDay();
        [$titulo, $lineas] = $momento === 'inicio' ? $this->inicio($hoy) : $this->cierre($hoy);
        $descripcion = implode("\n", $lineas);

        $this->info($titulo);
        $this->line($descripcion);

        try {
            $eventId = $calendar->crearEventoStatusMonitoreoPjud($titulo, $descripcion);
        } catch (Throwable $exception) {
            Log::error('No fue posible enviar el status del monitoreo PJUD.', ['momento' => $momento, 'exception' => $exception->getMessage()]);
            $this->error('No fue posible crear el evento en Google Calendar: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($eventId === null) {
            $this->warn('Google Calendar no está configurado: el status no se envió.');

            return self::SUCCESS;
        }

        Log::info('Status del monitoreo PJUD enviado.', ['momento' => $momento, 'evento' => $eventId]);
        $this->info("Evento creado: {$eventId}");

        return self::SUCCESS;
    }

    /** @return array{string, list<string>} */
    private function inicio(CarbonImmutable $hoy): array
    {
        return ['✅ Monitoreo PJUD iniciado', [
            'Fecha: '.$hoy->format('d-m-Y'),
            'Estado Diario que se consulta: '.$this->fechasConsultadas($hoy),
            'Tribunales en monitoreo: '.$this->tribunalesEnMonitoreo(),
            'Causas monitoreadas: '.Causa::query()->consultablesPjud()->whereHas('juzgado', fn ($q) => $q->whereNotNull('tribunal_pjud_codigo'))->count(),
            sprintf('Horario: %s a %s, cada %d minutos', self::horaInicio(), sprintf('%02d:00', config('pjud.horario.hora_fin', 18)), (int) config('pjud.horario.frecuencia_minutos', 60)),
            'Avisos pendientes de días anteriores: '.PjudMovimiento::query()->whereNull('notificado_at')->count(),
        ]];
    }

    /** @return array{string, list<string>} */
    private function cierre(CarbonImmutable $hoy): array
    {
        $registro = RegistroMonitoreoPjud::delDia($hoy->toDateString());
        $movimientos = PjudMovimiento::query()->whereBetween('created_at', [$hoy, $hoy->endOfDay()]);
        $detectados = (clone $movimientos)->count();
        $pendientes = (clone $movimientos)->whereNull('notificado_at')->count();

        $lineas = [
            'Fecha: '.$hoy->format('d-m-Y'),
            'Estado Diario consultado: '.$this->fechasConsultadas($hoy),
            'Revisiones ejecutadas: '.$registro['revisiones'],
            'Consultas a tribunales correctas: '.$registro['consultas_ok'],
            'Consultas con error: '.$registro['consultas_error'],
            'Movimientos detectados: '.$detectados,
            'Avisos enviados: '.($detectados - $pendientes),
            'Avisos pendientes: '.$pendientes,
        ];

        if ($registro['tribunales_error'] !== []) {
            $nombres = TribunalPjud::query()->whereIn('codigo', $registro['tribunales_error'])->pluck('nombre')->all();
            $lineas[] = 'Tribunales con error: '.implode(', ', $nombres);
        }

        $conProblemas = $registro['revisiones'] === 0 || $registro['consultas_error'] > 0 || $pendientes > 0;
        if ($registro['revisiones'] === 0) {
            $lineas[] = '⚠️ No se ejecutó ninguna revisión hoy: revise el cron del scheduler y el worker (slad-queue).';
        }

        return [$conProblemas ? '⚠️ Monitoreo PJUD finalizado con observaciones' : '🏁 Monitoreo PJUD finalizado', $lineas];
    }

    private function fechasConsultadas(CarbonImmutable $hoy): string
    {
        return RevisarEstadoDiarioPjud::fechasAConsultar($hoy)->map->format('d-m-Y')->implode(', ');
    }

    private function tribunalesEnMonitoreo(): int
    {
        return TribunalPjud::query()
            ->whereHas('juzgados.causas', fn ($query) => $query->scopes('consultablesPjud'))
            ->count();
    }

    public static function horaInicio(): string
    {
        return sprintf('%02d:00', (int) config('pjud.horario.hora_inicio', 8));
    }

    public static function horaCierre(): string
    {
        $minutos = max(0, min(59, (int) config('pjud.notificacion.status_minutos_cierre', 15)));

        return sprintf('%02d:%02d', (int) config('pjud.horario.hora_fin', 18), $minutos);
    }
}
