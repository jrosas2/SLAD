<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recordatorios:procesar')->everyMinute()->withoutOverlapping();

// Monitoreo del Estado Diario PJUD: cada hora, de 08:00 a 18:00 (hora de Chile).
// Fuera de ese rango no se consulta. Ver docs/pjud-estado-diario.md.
Schedule::command('pjud:revisar-estado-diario')
    ->hourly()
    ->between(sprintf('%02d:00', config('pjud.horario.hora_inicio', 8)), sprintf('%02d:00', config('pjud.horario.hora_fin', 18)))
    ->withoutOverlapping();
