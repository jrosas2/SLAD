<?php

namespace App\Services\Pjud;

use App\Models\TribunalPjud;
use Carbon\CarbonImmutable;

/**
 * Origen del listado del Estado Diario de un tribunal.
 *
 * La implementación por defecto (EstadoDiarioPjudClient) consulta la web del
 * PJUD. Si en el futuro se obtiene el Estado Diario de otra forma (API,
 * correo con Excel, etc.) basta con otra clase que implemente esta interfaz y
 * cambiar el binding en AppServiceProvider; el resto del flujo no cambia.
 */
interface FuenteEstadoDiario
{
    /**
     * @return list<EntradaEstadoDiario>
     */
    public function obtener(TribunalPjud $tribunal, CarbonImmutable $fecha): array;

    /**
     * Detalle de una causa del listado (tipo, estado, materia…), o null si
     * la fuente no lo ofrece. Sólo se pide para las causas de SLAD detectadas.
     */
    public function detalle(TribunalPjud $tribunal, CarbonImmutable $fecha, EntradaEstadoDiario $entrada): ?string;
}
