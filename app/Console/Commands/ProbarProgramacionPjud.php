<?php

namespace App\Console\Commands;

use App\Models\TribunalPjud;
use App\Services\Pjud\ProgramacionSalasPjudClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pjud:probar-programacion
    {corte : Código PJUD de la Corte (ej. 4901014 Temuco, 6050000 Suprema)}
    {--tipo= : Sólo un tipo de tabla (código, ej. 3 = ordinaria)}')]
#[Description('Consulta la programación de salas de una Corte y muestra lo que SLAD entiende (no guarda ni notifica nada)')]
class ProbarProgramacionPjud extends Command
{
    public function handle(ProgramacionSalasPjudClient $cliente): int
    {
        $corte = TribunalPjud::query()->find((int) $this->argument('corte'));

        if ($corte === null) {
            $this->error('Código no encontrado en el catálogo tribunales_pjud.');

            return self::FAILURE;
        }

        $condicion = ProgramacionSalasPjudClient::condicion($corte);

        if ($condicion === null) {
            $this->error("{$corte->nombre} no es una Corte: no publica programación de salas.");

            return self::FAILURE;
        }

        $this->info("Consultando la programación de {$corte->nombre} ({$condicion})…");

        $tipos = $cliente->tiposDeTabla($corte, $condicion);
        $this->line('Tipos de tabla: '.collect($tipos)->map(fn ($nombre, $codigo) => "{$codigo}={$nombre}")->implode(', '));

        $entradas = collect($cliente->obtener($corte, CarbonImmutable::today()));

        if (filled($this->option('tipo'))) {
            $entradas = $entradas->where('tipoTablaCodigo', (string) $this->option('tipo'))->values();
        }

        if ($entradas->isEmpty()) {
            $this->warn('No hay causas en tabla desde hoy.');

            return self::SUCCESS;
        }

        $this->table(
            ['Fecha', 'Tabla', 'Sala', 'N°', 'Ingreso', 'Causa'],
            $entradas->map(fn ($entrada) => [
                $entrada->fecha->format('d-m-Y'),
                $entrada->tipoTabla,
                $entrada->sala,
                $entrada->numeroOrden,
                $entrada->numeroIngreso,
                mb_strimwidth((string) $entrada->caratulado, 0, 60, '…'),
            ])->all(),
        );

        $this->info("{$entradas->count()} causa(s) en tabla, en {$entradas->unique(fn ($e) => $e->tipoTablaCodigo.$e->fecha->toDateString().$e->salaNumero)->count()} sala(s)-día.");

        return self::SUCCESS;
    }
}
