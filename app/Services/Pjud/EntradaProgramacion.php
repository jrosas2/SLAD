<?php

namespace App\Services\Pjud;

use Carbon\CarbonImmutable;

/**
 * Una causa en la tabla (programación de salas) de una Corte.
 */
final readonly class EntradaProgramacion
{
    public function __construct(
        public string $tipoTablaCodigo,
        public string $tipoTabla,
        public CarbonImmutable $fecha,
        public string $salaNumero,
        public string $sala,
        public string $numeroIngreso,
        public ?string $caratulado = null,
        public ?int $numeroOrden = null,
        public ?string $relator = null,
    ) {}
}
