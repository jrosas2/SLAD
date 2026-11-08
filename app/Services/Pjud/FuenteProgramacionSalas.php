<?php

namespace App\Services\Pjud;

use App\Models\TribunalPjud;
use Carbon\CarbonImmutable;

/**
 * Origen de la programación de salas (tablas) de una Corte.
 *
 * La implementación por defecto (ProgramacionSalasPjudClient) consulta la web
 * del PJUD; en las pruebas se reemplaza por una fuente falsa.
 */
interface FuenteProgramacionSalas
{
    /**
     * Causas en tabla de la Corte desde $desde (inclusive) en adelante.
     *
     * @return list<EntradaProgramacion>
     */
    public function obtener(TribunalPjud $corte, CarbonImmutable $desde): array;
}
