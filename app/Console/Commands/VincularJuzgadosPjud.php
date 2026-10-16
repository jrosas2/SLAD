<?php

namespace App\Console\Commands;

use App\Services\Pjud\VinculadorJuzgadosPjud;
use Database\Seeders\TribunalPjudSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pjud:vincular-juzgados
    {--simular : Muestra el resultado sin guardar cambios}
    {--sobrescribir : Recalcula también los juzgados que ya tienen código}')]
#[Description('Carga el catálogo de tribunales PJUD y asigna el código a cada juzgado de SLAD por nombre')]
class VincularJuzgadosPjud extends Command
{
    public function handle(VinculadorJuzgadosPjud $vinculador): int
    {
        $this->components->task('Cargando catálogo de tribunales PJUD', fn () => $this->callSilently('db:seed', [
            '--class' => TribunalPjudSeeder::class,
            '--force' => true,
        ]) === self::SUCCESS);

        $resultado = $vinculador->vincular(
            sobrescribir: (bool) $this->option('sobrescribir'),
            simular: (bool) $this->option('simular'),
        );

        if ($this->output->isVerbose() && $resultado['vinculados']->isNotEmpty()) {
            $this->table(['Juzgado SLAD', 'Código', 'Tribunal PJUD'], $resultado['vinculados']->map(fn (array $fila) => array_values($fila))->all());
        }

        $this->info(($this->option('simular') ? '[SIMULACIÓN] ' : '')."Vinculados ahora: {$resultado['vinculados']->count()} · Ya vinculados: {$resultado['ya_vinculados']} · Sin coincidencia: {$resultado['sin_coincidencia']->count()}");

        if ($resultado['sin_coincidencia']->isNotEmpty()) {
            $this->newLine();
            $this->warn('Estos juzgados no se pudieron vincular automáticamente. Asigne el código en Catálogos → Juzgados:');
            $resultado['sin_coincidencia']->each(fn (string $nombre) => $this->line("  - {$nombre}"));
        }

        return self::SUCCESS;
    }
}
