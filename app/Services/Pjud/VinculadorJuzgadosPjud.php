<?php

namespace App\Services\Pjud;

use App\Models\Juzgado;
use App\Models\TribunalPjud;
use App\Support\Pjud\NormalizadorPjud;
use Illuminate\Support\Collection;

/**
 * Asigna automáticamente el código PJUD a cada juzgado de SLAD comparando
 * nombres normalizados. Nunca sobrescribe un código ya asignado (salvo que
 * se pida explícitamente), de modo que las correcciones manuales se respetan.
 */
class VinculadorJuzgadosPjud
{
    /**
     * @return array{vinculados: Collection<int, array{juzgado: string, codigo: int, tribunal: string}>, sin_coincidencia: Collection<int, string>, ya_vinculados: int}
     */
    public function vincular(bool $sobrescribir = false, bool $simular = false): array
    {
        $equivalencias = (array) config('pjud.equivalencias_nombre', []);

        $tribunales = TribunalPjud::query()->get(['codigo', 'nombre', 'nombre_normalizado']);
        $porNombre = $tribunales->keyBy('nombre_normalizado');
        // Respaldo "1º X" ↔ "X": sólo entre nombres sin ordinal o con ordinal 1,
        // y sólo si el nombre resultante es único (nunca confunde 2º con 3º).
        $porNombreSinOrdinal = $tribunales
            ->filter(fn (TribunalPjud $tribunal) => $this->ordinalEsUnoONinguno($tribunal->nombre_normalizado))
            ->groupBy(fn (TribunalPjud $tribunal) => NormalizadorPjud::sinOrdinalInicial($tribunal->nombre_normalizado))
            ->filter(fn (Collection $grupo) => $grupo->count() === 1)
            ->map(fn (Collection $grupo) => $grupo->first());

        $vinculados = collect();
        $sinCoincidencia = collect();
        $yaVinculados = 0;

        Juzgado::query()->orderBy('nombre')->each(function (Juzgado $juzgado) use (
            $sobrescribir, $simular, $equivalencias, $porNombre, $porNombreSinOrdinal, $vinculados, $sinCoincidencia, &$yaVinculados
        ): void {
            if ($juzgado->tribunal_pjud_codigo !== null && ! $sobrescribir) {
                $yaVinculados++;

                return;
            }

            $nombre = NormalizadorPjud::nombreTribunal($juzgado->nombre, $equivalencias);
            $tribunal = $porNombre->get($nombre)
                ?? ($this->ordinalEsUnoONinguno($nombre) ? $porNombreSinOrdinal->get(NormalizadorPjud::sinOrdinalInicial($nombre)) : null);

            if (! $tribunal instanceof TribunalPjud) {
                $sinCoincidencia->push($juzgado->nombre);

                return;
            }

            if (! $simular) {
                $juzgado->forceFill(['tribunal_pjud_codigo' => $tribunal->codigo])->save();
            }

            $vinculados->push(['juzgado' => $juzgado->nombre, 'codigo' => $tribunal->codigo, 'tribunal' => $tribunal->nombre]);
        });

        return ['vinculados' => $vinculados, 'sin_coincidencia' => $sinCoincidencia, 'ya_vinculados' => $yaVinculados];
    }

    private function ordinalEsUnoONinguno(string $nombreNormalizado): bool
    {
        return preg_match('/^(\d+) /', $nombreNormalizado, $coincidencia) !== 1 || $coincidencia[1] === '1';
    }
}
