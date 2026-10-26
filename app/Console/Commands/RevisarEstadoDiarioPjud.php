<?php

namespace App\Console\Commands;

use App\Jobs\ConsultarEstadoDiarioTribunal;
use App\Models\TribunalPjud;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pjud:revisar-estado-diario
    {--fecha= : Fecha del Estado Diario (AAAA-MM-DD). Por defecto, hoy}
    {--forzar : Ejecuta aunque esté fuera del horario configurado}
    {--sync : Consulta en el mismo proceso, sin pasar por la cola}')]
#[Description('Revisa el Estado Diario del PJUD sólo en los tribunales donde SLAD tiene causas')]
class RevisarEstadoDiarioPjud extends Command
{
    public function handle(): int
    {
        if (! config('pjud.enabled')) {
            $this->warn('El monitoreo PJUD está desactivado (PJUD_ENABLED=false).');

            return self::SUCCESS;
        }

        $ahora = now();

        if (! $this->option('forzar') && ! $this->dentroDelHorario($ahora)) {
            $this->info('Fuera del horario de consultas ('.$this->rangoHorario().'). No se consulta al PJUD.');

            return self::SUCCESS;
        }

        $fecha = filled($this->option('fecha'))
            ? CarbonImmutable::parse((string) $this->option('fecha'))->startOfDay()
            : $ahora->toImmutable()->startOfDay();

        // Sólo tribunales donde SLAD tiene causas VIGENTES con número (las cerradas no se consultan).
        $tribunales = TribunalPjud::query()
            ->whereHas('juzgados.causas', fn ($query) => $query->scopes('consultablesPjud'))
            ->orderBy('codigo')
            ->get(['codigo', 'nombre']);

        if ($tribunales->isEmpty()) {
            $this->warn('No hay tribunales con causas vigentes vinculadas a un código PJUD. Revise el estado de las causas o ejecute: php artisan pjud:vincular-juzgados');

            return self::SUCCESS;
        }

        $pausa = max(0, (int) config('pjud.estado_diario.segundos_entre_tribunales', 5));

        // Hoy y los N días anteriores (el PJUD publica con desfase). Con --fecha, sólo esa fecha.
        $diasAtras = filled($this->option('fecha')) ? 0 : max(0, (int) config('pjud.estado_diario.dias_atras', 1));
        $fechas = collect(range(0, $diasAtras))->map(fn (int $dias) => $fecha->subDays($dias));

        /** @var list<array{int, string}> $consultas  [código tribunal, fecha] */
        $consultas = [];
        foreach ($tribunales as $tribunal) {
            foreach ($fechas as $dia) {
                $consultas[] = [(int) $tribunal->codigo, $dia->toDateString()];
            }
        }

        foreach ($consultas as $indice => [$codigo, $dia]) {
            $job = new ConsultarEstadoDiarioTribunal($codigo, $dia);

            if ($this->option('sync')) {
                dispatch_sync($job);
                if ($pausa > 0 && $indice < count($consultas) - 1) {
                    sleep($pausa);
                }

                continue;
            }

            // Se escalonan las consultas para no saturar al PJUD.
            dispatch($job->delay(now()->addSeconds($indice * $pausa)));
        }

        $this->info("{$tribunales->count()} tribunal(es) en revisión · fechas: ".$fechas->map->format('d-m-Y')->implode(', ').'.');

        return self::SUCCESS;
    }

    private function dentroDelHorario(\DateTimeInterface $momento): bool
    {
        $hora = (int) $momento->format('G');

        return $hora >= (int) config('pjud.horario.hora_inicio', 8)
            && $hora <= (int) config('pjud.horario.hora_fin', 18);
    }

    private function rangoHorario(): string
    {
        return sprintf('%02d:00 a %02d:00', config('pjud.horario.hora_inicio', 8), config('pjud.horario.hora_fin', 18));
    }
}
