<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;

class HistoricalHeaderMapper
{
    /** @var array<string, string> */
    private const ALIASES = [
        'NOMBRE CAUSA' => 'nombre',
        'NOMBRE CASUSA' => 'nombre',
        'FECHA CAUSA' => 'fecha_causa',
        'FECHA DE INGRESO' => 'fecha_ingreso',
        'CIUDAD' => 'ciudad',
        'NUMERO DE JUZGADO' => 'juzgado',
        'NRO DE JUZGADO' => 'juzgado',
        'JUZGADO' => 'juzgado',
        'MATERIA' => 'materia',
        'SUB MATERIA' => 'submateria',
        'SUBMATERIA' => 'submateria',
        'ESTADO PROCESAL' => 'estado_procesal',
        'DIRECCION' => 'direccion',
        'A CARGO' => 'responsable_codigo',
        'NRO CAUSA' => 'numero_causa',
        'NUMERO CAUSA' => 'numero_causa',
        'ACCION' => 'accion',
        'ESTADO' => 'estado_causa',
        'MONTO DEMANDADO' => 'monto_demandado',
        'OBSERVACION CAUSA' => 'observacion_causa',
        'OBSERVACION IMPORTANTE' => 'observacion_importante',
        'INGRESO' => 'ingreso',
        'EGRESO' => 'egreso',
        'CON SIN COTIZACIONES' => 'tiene_cotizaciones',
        'FUNCIONARIO A USUARIO A' => 'funcionario_usuario_descartado',
    ];

    /** @param array<int, mixed> $headers
     * @return array<int, string>
     */
    public function map(array $headers): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if (trim((string) $header) === '°') {
                $mapped[$index] = 'juzgado';

                continue;
            }

            $normalized = $this->normalize((string) $header);

            if (isset(self::ALIASES[$normalized])) {
                $mapped[$index] = self::ALIASES[$normalized];
            }
        }

        return $mapped;
    }

    /** @param array<int, string> $mapped */
    public function hasMinimumStructure(array $mapped): bool
    {
        return in_array('nombre', $mapped, true)
            && in_array('materia', $mapped, true)
            && in_array('numero_causa', $mapped, true);
    }

    public function normalize(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/u', ' ')
            ->squish();
    }

    public function normalizeComparable(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $this->normalize((string) $value);
    }
}
