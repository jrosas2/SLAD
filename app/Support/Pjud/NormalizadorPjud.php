<?php

namespace App\Support\Pjud;

/**
 * Normaliza los dos datos clave del match con el Estado Diario:
 * el número de ingreso (Rol/RIT) y el nombre del tribunal.
 *
 * Es PHP puro (sin dependencias de Laravel) para poder probarlo aislado.
 */
final class NormalizadorPjud
{
    private const ACENTOS = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U', 'ñ' => 'N',
        'º' => '', '°' => '', 'ª' => '', '–' => '-', '—' => '-',
    ];

    /**
     * "C-01234-2025", "c 1234 - 2025" o "Rol C-1234-2025" → "C-1234-2025".
     * "1234-2025" (sin letra, ej. Cortes) → "1234-2025".
     */
    public static function numeroIngreso(?string $valor): ?string
    {
        $texto = self::base($valor);

        if ($texto === '') {
            return null;
        }

        $texto = (string) preg_replace('/[()\[\]\.,:]/', ' ', $texto); // "(AMP) 11-2026" → "AMP 11-2026"
        $texto = (string) preg_replace('/\b(ROL|RIT|RUC|NRO|NUMERO|N)\b/', ' ', $texto);

        if (preg_match('/([A-Z]{1,15})\s*-?\s*0*(\d+)\s*-\s*(\d{4})/', $texto, $m) === 1) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        if (preg_match('/0*(\d+)\s*-\s*(\d{4})/', $texto, $m) === 1) {
            return "{$m[1]}-{$m[2]}";
        }

        $compacto = (string) preg_replace('/[^A-Z0-9]/', '', $texto);

        return $compacto === '' ? null : $compacto;
    }

    public const COINCIDENCIA_EXACTA = 'exacta';

    public const COINCIDENCIA_COMPATIBLE = 'compatible';

    public const COINCIDENCIA_PARCIAL = 'parcial';

    /**
     * Separa un número de ingreso en sus partes. Acepta formatos completos e
     * incompletos, como se hayan registrado en SLAD:
     *   "Ordinaria-5329-2025" → ['letra' => 'ORDINARIA', 'numero' => '5329', 'anio' => '2025']
     *   "O-5329-2025"         → ['letra' => 'O',         'numero' => '5329', 'anio' => '2025']
     *   "5329-2025"           → ['letra' => null,        'numero' => '5329', 'anio' => '2025']
     *   "5329" / "O-5329"     → año null
     *
     * @return array{letra: ?string, numero: string, anio: ?string}|null
     */
    public static function componentes(?string $valor): ?array
    {
        $texto = self::base($valor);
        $texto = (string) preg_replace('/[()\[\]\.,:]/', ' ', $texto);
        $texto = (string) preg_replace('/\b(ROL|RIT|RUC|NRO|NUMERO|N)\b/', ' ', $texto);

        if (preg_match('/(?:([A-Z]{1,15})\s*-?\s*)?0*(\d+)(?:\s*-\s*(\d{4}))?/', $texto, $m) !== 1) {
            return null;
        }

        return [
            'letra' => $m[1] !== '' ? $m[1] : null,
            'numero' => $m[2],
            'anio' => ($m[3] ?? '') !== '' ? $m[3] : null,
        ];
    }

    /**
     * Compara el número del listado PJUD con el registrado en SLAD.
     *
     * - exacta:     letra, número y año iguales ("C-5329-2025" = "C-5329-2025").
     * - compatible: número y año iguales; en SLAD falta la letra o es su
     *               abreviatura ("O-5329-2025" o "5329-2025" vs "Ordinaria-5329-2025").
     * - parcial:    en SLAD falta el año; calza sólo por número ("5329" vs
     *               "Ordinaria-5329-2025"). Se avisa marcado para verificar.
     * - null:       no calza (otro número, otro año o una letra distinta).
     *
     * @param  array{letra: ?string, numero: string, anio: ?string}  $pjud
     * @param  array{letra: ?string, numero: string, anio: ?string}  $slad
     */
    public static function coincidencia(array $pjud, array $slad): ?string
    {
        if ($pjud['numero'] !== $slad['numero'] || ! self::letrasCompatibles($pjud['letra'], $slad['letra'])) {
            return null;
        }

        if ($slad['anio'] === null) {
            return self::COINCIDENCIA_PARCIAL;
        }

        if ($pjud['anio'] !== null && $pjud['anio'] !== $slad['anio']) {
            return null;
        }

        return $pjud['letra'] === $slad['letra'] ? self::COINCIDENCIA_EXACTA : self::COINCIDENCIA_COMPATIBLE;
    }

    /**
     * Número y año de un ingreso de Corte, ignorando todo lo demás.
     *
     * El PJUD no escribe las causas de las Cortes con un formato fijo: cambian
     * los espacios, los guiones y puede anteponer el tipo o la materia. Lo
     * único estable es el número y el año:
     *   "1436 - 2026", "1436-2026", "1436 -2026", "Rol 1436-2026",
     *   "(Laboral) 1436 - 2026", "Laboral-1436-2026", "N° 1.436 – 2026",
     *   "1436/2026", "Ingreso 01436-2026"  → ['numero' => '1436', 'anio' => '2026']
     *   "1436" → ['numero' => '1436', 'anio' => null]
     *
     * @return array{numero: string, anio: ?string}|null
     */
    public static function numeroYAnio(?string $valor): ?array
    {
        $texto = self::base($valor);

        if ($texto === '') {
            return null;
        }

        // Separador de miles: "1.436" → "1436".
        $texto = (string) preg_replace('/(?<=\d)[.,](?=\d{3}(?!\d))/', '', $texto);

        // Número seguido del año (guion, barra o espacios entre ambos). Si hay
        // varios, el último: el prefijo puede traer otros números.
        if (preg_match_all('/(?<!\d)0*(\d{1,7})(?:\s*[-\/]\s*|\s+)((?:19|20)\d{2})(?!\d)/', $texto, $coincidencias, PREG_SET_ORDER) > 0) {
            $ultima = $coincidencias[array_key_last($coincidencias)];

            return ['numero' => $ultima[1], 'anio' => $ultima[2]];
        }

        if (preg_match('/(?<!\d)0*(\d{1,7})(?!\d)/', $texto, $m) === 1) {
            return ['numero' => $m[1], 'anio' => null];
        }

        return null;
    }

    /**
     * Compara número y año (ver numeroYAnio), sin considerar letras ni prefijos.
     *
     * - exacta:  número y año iguales.
     * - parcial: número igual, pero a uno de los dos le falta el año.
     * - null:    otro número u otro año.
     *
     * @param  array{numero: string, anio: ?string}  $pjud
     * @param  array{numero: string, anio: ?string}  $slad
     */
    public static function coincidenciaNumeroAnio(array $pjud, array $slad): ?string
    {
        if ($pjud['numero'] !== $slad['numero']) {
            return null;
        }

        if ($pjud['anio'] === null || $slad['anio'] === null) {
            return self::COINCIDENCIA_PARCIAL;
        }

        return $pjud['anio'] === $slad['anio'] ? self::COINCIDENCIA_EXACTA : null;
    }

    /**
     * Sin letra en alguno de los lados → compatible. Con letra en ambos, una
     * debe ser abreviatura de la otra ("O" ↔ "ORDINARIA"), pero "C" ≠ "V".
     */
    private static function letrasCompatibles(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * "1º Juzgado de Letras en lo Civil de Antofagasta" → "1 JUZGADO CIVIL DE ANTOFAGASTA".
     *
     * @param  array<string, string>  $equivalencias  patrón regex → reemplazo
     */
    public static function nombreTribunal(?string $valor, array $equivalencias = []): string
    {
        $texto = self::base($valor);
        $texto = (string) preg_replace('/[^A-Z0-9 ]/', ' ', $texto);
        $texto = trim((string) preg_replace('/\s+/', ' ', $texto));

        foreach ($equivalencias as $patron => $reemplazo) {
            $texto = (string) preg_replace($patron, $reemplazo, $texto);
        }

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * Variante sin ordinal inicial, para tribunales únicos que SLAD registra
     * como "1º Juzgado..." y el PJUD sin número (o al revés).
     */
    public static function sinOrdinalInicial(string $nombreNormalizado): string
    {
        return (string) preg_replace('/^\d+ /', '', $nombreNormalizado);
    }

    private static function base(?string $valor): string
    {
        $texto = strtr(trim((string) $valor), self::ACENTOS);

        return trim(mb_strtoupper($texto, 'UTF-8'));
    }
}
