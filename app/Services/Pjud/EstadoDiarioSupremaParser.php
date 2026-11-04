<?php

namespace App\Services\Pjud;

/**
 * Lee el Estado Diario de la Corte Suprema.
 *
 * A diferencia del resto de los tribunales (tabla HTML), la Corte Suprema se
 * publica como JSON con la lista completa del día (servicio getDailyStatementsXls):
 *   [{"CRR_INGRESO_RECURSO": 407, "FEC_ERA_RECURSO": 2026, "CARATULADO": "…",
 *     "CANT_RESOLUCIONES": 1, "COD_LIBRO": 2, …}, …]
 * El número de ingreso es "número-año" (ej. "407-2026"), único en el día.
 */
class EstadoDiarioSupremaParser
{
    /**
     * @return list<EntradaEstadoDiario>
     */
    public function parse(string $json): array
    {
        $filas = json_decode(trim($json), true);

        if (! is_array($filas)) {
            return [];
        }

        $entradas = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $numero = $this->texto($fila['CRR_INGRESO_RECURSO'] ?? null);
            $anio = $this->texto($fila['FEC_ERA_RECURSO'] ?? null);

            if ($numero === null || $anio === null) {
                continue;
            }

            $datos = [];
            foreach (['libro' => 'COD_LIBRO', 'reservado' => 'FLG_RESERVADO'] as $clave => $columna) {
                $valor = $this->texto($fila[$columna] ?? null);
                if ($valor !== null) {
                    $datos[$clave] = $valor;
                }
            }

            $entradas[] = new EntradaEstadoDiario(
                numeroIngreso: "{$numero}-{$anio}",
                caratulado: $this->texto($fila['CARATULADO'] ?? null),
                tramite: $this->texto($fila['CANT_RESOLUCIONES'] ?? null),
                datos: $datos,
            );
        }

        return $entradas;
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
