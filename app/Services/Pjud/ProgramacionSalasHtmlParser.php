<?php

namespace App\Services\Pjud;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lee las dos respuestas HTML de "Programación de Salas" de las Cortes:
 *
 *  - salas(): listado Fecha | Sala | Relator. Cada sala trae un enlace
 *    linkConstitucionSala('1','4901014','3','09/11/2026','') con el número de
 *    sala y la fecha exactos que pide la constitución de sala.
 *  - causas(): constitución de una sala en un día: N° | Causa | Ingreso
 *    ("1436 - 2026"), más la tabla Cargo | Nombre (relator).
 *
 * Las columnas se buscan por NOMBRE, no por posición.
 */
class ProgramacionSalasHtmlParser
{
    /**
     * @return list<array{fecha: CarbonImmutable, sala_numero: string, sala: string, relator: ?string}>
     */
    public function salas(string $html): array
    {
        $xpath = $this->xpath($html);

        if ($xpath === null) {
            return [];
        }

        $salas = [];

        foreach ($this->tablas($xpath) as $tabla) {
            $mapa = $this->columnas($xpath, $tabla, ['fecha' => ['fecha'], 'sala' => ['sala'], 'relator' => ['relator']]);

            if (! isset($mapa['fecha'], $mapa['sala'])) {
                continue;
            }

            foreach ($this->filas($xpath, $tabla) as $celdas) {
                $celdaSala = $celdas[$mapa['sala']] ?? null;

                if (! $celdaSala instanceof DOMElement) {
                    continue;
                }

                $enlace = $this->argumentosEnlace($xpath, $celdaSala);
                $fecha = $this->fecha($enlace[3] ?? $this->texto($celdas[$mapa['fecha']] ?? null));
                $numero = trim((string) ($enlace[0] ?? ''));

                if ($fecha === null || $numero === '') {
                    continue;
                }

                $relator = isset($mapa['relator']) ? $this->texto($celdas[$mapa['relator']] ?? null) : '';

                $salas[] = [
                    'fecha' => $fecha,
                    'sala_numero' => $numero,
                    'sala' => $this->texto($celdaSala),
                    'relator' => $relator !== '' ? $relator : null,
                ];
            }

            break;
        }

        return $salas;
    }

    /**
     * @return array{relator: ?string, causas: list<array{orden: ?int, caratulado: ?string, numero_ingreso: string}>}
     */
    public function causas(string $html): array
    {
        $xpath = $this->xpath($html);
        $resultado = ['relator' => null, 'causas' => []];

        if ($xpath === null) {
            return $resultado;
        }

        foreach ($this->tablas($xpath) as $tabla) {
            $cargos = $this->columnas($xpath, $tabla, ['cargo' => ['cargo'], 'nombre' => ['nombre']]);

            if (isset($cargos['cargo'], $cargos['nombre'])) {
                foreach ($this->filas($xpath, $tabla) as $celdas) {
                    if (str_contains($this->comparable($this->texto($celdas[$cargos['cargo']] ?? null)), 'relator')) {
                        $nombre = $this->texto($celdas[$cargos['nombre']] ?? null);
                        $resultado['relator'] = $nombre !== '' ? $nombre : null;
                    }
                }

                continue;
            }

            $mapa = $this->columnas($xpath, $tabla, ['ingreso' => ['ingreso', 'rol'], 'causa' => ['causa', 'caratula'], 'orden' => ['n']]);

            if (! isset($mapa['ingreso'])) {
                continue;
            }

            foreach ($this->filas($xpath, $tabla) as $celdas) {
                $ingreso = $this->texto($celdas[$mapa['ingreso']] ?? null);

                if ($ingreso === '' || preg_match('/\d/', $ingreso) !== 1) {
                    continue;
                }

                $orden = isset($mapa['orden']) ? $this->texto($celdas[$mapa['orden']] ?? null) : '';
                $caratulado = isset($mapa['causa']) ? $this->texto($celdas[$mapa['causa']] ?? null) : '';

                $resultado['causas'][] = [
                    'orden' => ctype_digit($orden) ? (int) $orden : null,
                    'caratulado' => $caratulado !== '' ? $caratulado : null,
                    'numero_ingreso' => $ingreso,
                ];
            }
        }

        return $resultado;
    }

    private function xpath(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($dom);
    }

    /** @return list<DOMElement> */
    private function tablas(DOMXPath $xpath): array
    {
        $tablas = [];
        foreach ($xpath->query('//table') ?: [] as $tabla) {
            if ($tabla instanceof DOMElement) {
                $tablas[] = $tabla;
            }
        }

        return $tablas;
    }

    /**
     * Ubica cada campo por el texto del encabezado (sin tildes ni mayúsculas).
     * Para "N°" se exige coincidencia exacta, para no confundirlo con "Nombre".
     *
     * @param  array<string, list<string>>  $alias
     * @return array<string, int>
     */
    private function columnas(DOMXPath $xpath, DOMElement $tabla, array $alias): array
    {
        $mapa = [];

        foreach ($xpath->query('.//th', $tabla) ?: [] as $indice => $th) {
            $titulo = $this->comparable($th->textContent);

            foreach ($alias as $campo => $nombres) {
                if (isset($mapa[$campo])) {
                    continue;
                }

                foreach ($nombres as $nombre) {
                    $calza = $nombre === 'n' ? $titulo === 'n' : str_contains($titulo, $nombre);

                    if ($calza) {
                        $mapa[$campo] = $indice;
                        break 2;
                    }
                }
            }
        }

        return $mapa;
    }

    /** @return list<list<DOMElement>> */
    private function filas(DOMXPath $xpath, DOMElement $tabla): array
    {
        $filas = [];

        foreach ($xpath->query('.//tr[td]', $tabla) ?: [] as $tr) {
            $celdas = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $td) {
                if ($td instanceof DOMElement) {
                    $celdas[] = $td;
                }
            }

            if ($celdas !== []) {
                $filas[] = $celdas;
            }
        }

        return $filas;
    }

    /**
     * Argumentos de linkConstitucionSala('1','4901014','3','09/11/2026','').
     *
     * @return list<string>
     */
    private function argumentosEnlace(DOMXPath $xpath, DOMElement $celda): array
    {
        foreach ($xpath->query('.//*[@onclick]', $celda) ?: [] as $elemento) {
            if (! $elemento instanceof DOMElement) {
                continue;
            }

            if (preg_match('/linkConstitucionSala\s*\((.*)\)/s', $elemento->getAttribute('onclick'), $m) === 1
                && preg_match_all("/'([^']*)'/", $m[1], $argumentos) > 0) {
                return $argumentos[1];
            }
        }

        return [];
    }

    private function fecha(string $valor): ?CarbonImmutable
    {
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $valor, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
    }

    private function texto(?DOMElement $elemento): string
    {
        return $elemento === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $elemento->textContent));
    }

    private function comparable(string $texto): string
    {
        $texto = strtr(mb_strtolower(trim($texto), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', '°' => '', 'º' => '']);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }
}
