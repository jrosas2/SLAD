<?php

namespace App\Console\Commands;

use App\Jobs\ConsultarProgramacionCorte;
use App\Models\TribunalPjud;
use App\Support\Pjud\RegistroMonitoreoPjud;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('pjud:revisar-programacion
    {--corte= : Código PJUD de una sola Corte (ej. 4901014)}
    {--forzar : Ejecuta aunque esté fuera del horario configurado}
    {--sync : Consulta en el mismo proceso, sin pasar por la cola}')]
#[Description('Revisa la programación de salas (tablas) de las Cortes donde SLAD tiene causas vigentes')]
class RevisarProgramacionPjud extends Command
{
    public function handle(): int
    {
        if (! config('pjud.programacion.enabled')) {
            $this->warn('El monitoreo de programación PJUD está desactivado (PJUD_PROGRAMACION_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! $this->option('forzar') && ! self::dentroDelHorario(now())) {
            $this->info('Fuera del horario de la programación ('.self::rangoHorario().'). No se consulta al PJUD.');

            return self::SUCCESS;
        }

        $cortes = self::cortesEnMonitoreo();

        if (filled($this->option('corte'))) {
            $cortes = $cortes->where('codigo', (int) $this->option('corte'))->values();
        }

        if ($cortes->isEmpty()) {
            $this->warn('No hay Cortes con causas consultables vinculadas a un código PJUD. Revise el juzgado y el estado de las causas de Corte.');

            return self::SUCCESS;
        }

        RegistroMonitoreoPjud::sumar('revisiones', 1, RegistroMonitoreoPjud::PROGRAMACION);

        $pausa = max(0, (int) config('pjud.programacion.segundos_entre_cortes', 10));

        foreach ($cortes->values() as $indice => $corte) {
            $job = new ConsultarProgramacionCorte((int) $corte->codigo);

            if ($this->option('sync')) {
                $this->line("Consultando {$corte->nombre}…");
                dispatch_sync($job);

                continue;
            }

            dispatch($job->delay(now()->addSeconds($indice * $pausa)));
        }

        $this->info("{$cortes->count()} Corte(s) en revisión: ".$cortes->pluck('nombre')->implode(', ').'.');

        return self::SUCCESS;
    }

    /**
     * Cortes (Suprema y Apelaciones) con al menos una causa consultable de SLAD.
     * No hay Cortes fijas: salen de la base de datos, en cualquier jurisdicción.
     *
     * @return Collection<int, TribunalPjud>
     */
    public static function cortesEnMonitoreo(): Collection
    {
        $estados = (array) config('pjud.programacion.estados_causa_consultables', ['VIGENTE']);

        return TribunalPjud::query()
            ->whereIn('tipo_juzgado', [1, 2])
            ->whereHas('juzgados.causas', fn (Builder $query) => $query->scopes(['consultablesPjud' => [$estados]]))
            ->orderBy('codigo')
            ->get(['codigo', 'tipo_juzgado', 'nombre']);
    }

    /**
     * Expresión cron según PJUD_PROGRAMACION_FRECUENCIA_MINUTOS (5 a 60).
     */
    public static function expresionCron(): string
    {
        $minutos = max(5, min(60, (int) config('pjud.programacion.horario.frecuencia_minutos', 60)));

        return $minutos >= 60 ? '0 * * * *' : "*/{$minutos} * * * *";
    }

    public static function dentroDelHorario(\DateTimeInterface $momento): bool
    {
        $hora = (int) $momento->format('G');

        return $hora >= (int) config('pjud.programacion.horario.hora_inicio', 14)
            && $hora <= (int) config('pjud.programacion.horario.hora_fin', 20);
    }

    public static function rangoHorario(): string
    {
        return sprintf('%02d:00 a %02d:00', config('pjud.programacion.horario.hora_inicio', 14), config('pjud.programacion.horario.hora_fin', 20));
    }
}
