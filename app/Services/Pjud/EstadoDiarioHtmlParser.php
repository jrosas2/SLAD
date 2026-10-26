<?php

namespace App\Services\Pjud;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Convierte el HTML del Estado Diario en una lista de EntradaEstadoDiario.
 *
 * Busca la primera tabla cuyo encabezado contenga una columna de número de
 * ingreso (Rol / RIT / N° Ingreso) y lee las columnas por NOMBRE, no por
 * posición, así un cambio de orden en la página del PJUD no rompe el match.
 */
class EstadoDiarioHtmlParser
{
    /**
     * @param  array<string, list<string>>  $alias  campo → posibles encabezados (config pjud.estado_diario.columnas)
     */
    public function __construct(private readonly array $alias) {}

    /**
     * @return list<EntradaEstadoDiario>
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//table') ?: [] as $tabla) {
            if (! $tabla instanceof DOMElement) {
                continue;
            }

            [$filas, $datos] = $this->filas($xpath, $tabla);
            $indiceEncabezado = $this->buscarEncabezado($filas);

            if ($indiceEncabezado === null) {
                continue;
            }

            $encabezados = $filas[$indiceEncabezado];
            $mapa = $this->mapearColumnas($encabezados);

            return $this->leerFilas(
                array_slice($filas, $indiceEncabezado + 1),
                array_slice($datos, $indiceEncabezado + 1),
                $encabezados,
                $mapa,
            );
        }

        return [];
    }

    /**
     * Devuelve [filas de texto, atributos data-* de cada fila].
     * El PJUD pone en el enlace del número de ingreso datos como data-rol,
     * data-era, data-tipocausa o data-codoracle, que sirven para pedir el detalle.
     *
     * @return array{0: list<list<string>>, 1: list<array<string, string>>}
     */
    private function filas(DOMXPath $xpath, DOMElement $tabla): array
    {
        $filas = [];
        $datos = [];

        foreach ($xpath->query('.//tr', $tabla) ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $celdas = [];

            foreach ($xpath->query('./th|./td', $tr) ?: [] as $celda) {
                if ($celda instanceof DOMElement) {
                    $celdas[] = trim((string) preg_replace('/\s+/u', ' ', $celda->textContent));
                }
            }

            if ($celdas === []) {
                continue;
            }

            $atributos = [];
            foreach ($xpath->query('.//*[@*[starts-with(name(), "data-")]]', $tr) ?: [] as $elemento) {
                if (! $elemento instanceof DOMElement) {
                    continue;
                }

                foreach ($elemento->attributes as $atributo) {
                    if (str_starts_with($atributo->name, 'data-') && ! isset($atributos[substr($atributo->name, 5)])) {
                        $atributos[substr($atributo->name, 5)] = trim($atributo->value);
                    }
                }
            }

            $filas[] = $celdas;
            $datos[] = $atributos;
        }

        return [$filas, $datos];
    }

    /**
     * @param  list<list<string>>  $filas
     */
    private function buscarEncabezado(array $filas): ?int
    {
        foreach ($filas as $indice => $celdas) {
            $mapa = $this->mapearColumnas($celdas);

            if (isset($mapa['numero_ingreso'])) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $encabezados
     * @return array<string, int> campo → índice de columna
     */
    private function mapearColumnas(array $encabezados): array
    {
        $mapa = [];
        $normalizados = array_map(fn (string $texto) => $this->normalizarEncabezado($texto), $encabezados);

        foreach ($this->alias as $campo => $posibles) {
            foreach ($posibles as $posible) {
                $posible = $this->normalizarEncabezado($posible);

                foreach ($normalizados as $indice => $encabezado) {
                    if ($encabezado === '' || in_array($indice, $mapa, true)) {
                        continue;
                    }

                    // Coincidencia exacta o por palabras completas ("rit" no calza con "prioritario").
                    if ($encabezado === $posible || preg_match('/\b'.preg_quote($posible, '/').'\b/', $encabezado) === 1) {
                        $mapa[$campo] = $indice;

                        continue 3;
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * @param  list<list<string>>  $filas
     * @param  list<array<string, string>>  $datos
     * @param  list<string>  $encabezados
     * @param  array<string, int>  $mapa
     * @return list<EntradaEstadoDiario>
     */
    private function leerFilas(array $filas, array $datos, array $encabezados, array $mapa): array
    {
        $entradas = [];

        foreach ($filas as $posicion => $celdas) {
            $valor = fn (string $campo): ?string => isset($mapa[$campo]) && ($celdas[$mapa[$campo]] ?? '') !== ''
                ? $celdas[$mapa[$campo]]
                : null;

            $numero = $valor('numero_ingreso');

            if ($numero === null) {
                continue;
            }

            $columnas = [];
            foreach ($encabezados as $indice => $encabezado) {
                $columnas[$encabezado !== '' ? $encabezado : "col_{$indice}"] = $celdas[$indice] ?? '';
            }

            $entradas[] = new EntradaEstadoDiario(
                numeroIngreso: $numero,
                tribunal: $valor('tribunal'),
                caratulado: $valor('caratulado'),
                tramite: $valor('tramite'),
                fecha: $valor('fecha'),
                columnas: $columnas,
                datos: $datos[$posicion] ?? [],
            );
        }

        return $entradas;
    }

    private function normalizarEncabezado(string $texto): string
    {
        $texto = mb_strtolower(strtr($texto, ['Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'º' => '', '°' => '', '.' => ' ']), 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $texto)));
    }
}
