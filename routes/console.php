<?php

use App\Console\Commands\RevisarEstadoDiarioPjud;
use App\Console\Commands\RevisarProgramacionPjud;
use App\Console\Commands\StatusMonitoreoPjud;
use App\Console\Commands\StatusProgramacionPjud;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recordatorios:procesar')->everyMinute()->withoutOverlapping();

// Monitoreo del Estado Diario PJUD: cada PJUD_FRECUENCIA_MINUTOS (por defecto 60),
// de 08:00 a 18:00 (hora de Chile). Fuera de ese rango no se consulta.
// Ver docs/pjud-estado-diario.md.
Schedule::command('pjud:revisar-estado-diario')
    ->cron(RevisarEstadoDiarioPjud::expresionCron())
    ->between(sprintf('%02d:00', config('pjud.horario.hora_inicio', 8)), sprintf('%02d:00', config('pjud.horario.hora_fin', 18)))
    ->withoutOverlapping();

// Aviso de status del monitoreo PJUD en Google Calendar: al iniciar el horario
// y al cerrarlo (con el resumen del día). Se desactiva con PJUD_STATUS=false.
Schedule::command('pjud:status-monitoreo inicio')
    ->dailyAt(StatusMonitoreoPjud::horaInicio())
    ->when(fn () => (bool) config('pjud.notificacion.status', true));

Schedule::command('pjud:status-monitoreo cierre')
    ->dailyAt(StatusMonitoreoPjud::horaCierre())
    ->when(fn () => (bool) config('pjud.notificacion.status', true));

// Programación de salas (tablas de las Cortes): horario y frecuencia propios
// (PJUD_PROGRAMACION_*), porque las tablas se publican en otro horario que el
// Estado Diario. Las Cortes salen de las causas vigentes de SLAD.
Schedule::command('pjud:revisar-programacion')
    ->cron(RevisarProgramacionPjud::expresionCron())
    ->between(StatusProgramacionPjud::horaInicio(), sprintf('%02d:00', config('pjud.programacion.horario.hora_fin', 20)))
    ->withoutOverlapping();

Schedule::command('pjud:status-programacion inicio')
    ->dailyAt(StatusProgramacionPjud::horaInicio())
    ->when(fn () => (bool) config('pjud.programacion.notificacion.status', true));

Schedule::command('pjud:status-programacion cierre')
    ->dailyAt(StatusProgramacionPjud::horaCierre())
    ->when(fn () => (bool) config('pjud.programacion.notificacion.status', true));
