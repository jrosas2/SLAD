<?php

namespace App\Services\Pjud;

/**
 * Una fila del listado del Estado Diario del PJUD.
 */
final readonly class EntradaEstadoDiario
{
    /**
     * @param  array<string, string>  $columnas  Fila original (encabezado → valor), para diagnóstico.
     * @param  array<string, string>  $datos  Atributos data-* del enlace (rol, era, tipocausa, codoracle…).
     */
    public function __construct(
        public string $numeroIngreso,
        public ?string $tribunal = null,
        public ?string $caratulado = null,
        public ?string $tramite = null,
        public ?string $fecha = null,
        public array $columnas = [],
        public array $datos = [],
    ) {}
}
