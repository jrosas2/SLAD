<?php

namespace App\Services\Pjud;

use App\Models\Causa;
use App\Models\PjudProgramacion;
use App\Models\TribunalPjud;
use App\Support\Pjud\NormalizadorPjud;
use Illuminate\Support\Collection;

/**
 * Cruza la programación de salas de una Corte con las causas de SLAD.
 *
 * El PJUD no tiene un formato fijo para el ingreso de las Cortes ("1436 - 2026",
 * "1436-2026", "Rol 1436-2026", "(Laboral) 1436 - 2026"…): sólo se comparan
 * el NÚMERO y el AÑO (NormalizadorPjud::numeroYAnio), ignorando espacios,
 * guiones, prefijos, separador de miles y ceros a la izquierda. El tribunal
 * es siempre la Corte consultada: sólo se cruzan las causas cuyo juzgado está
 * vinculado a ese código PJUD.
 */
class DetectorProgramacionPjud
{
    /**
     * @param  list<EntradaProgramacion>  $entradas
     * @return list<PjudProgramacion> Sólo las programaciones NUEVAS.
     */
    public function registrar(TribunalPjud $corte, array $entradas): array
    {
        if ($entradas === []) {
            return [];
        }

        $permitirParcial = (bool) config('pjud.programacion.coincidencia_parcial', true);
        $causasPorNumero = $this->causasDeLaCorte($corte);

        if ($causasPorNumero->isEmpty()) {
            return [];
        }

        $nuevas = [];

        foreach ($entradas as $entrada) {
            $pjud = NormalizadorPjud::numeroYAnio($entrada->numeroIngreso);

            if ($pjud === null || ! $causasPorNumero->has($pjud['numero'])) {
                continue;
            }

            foreach ($causasPorNumero->get($pjud['numero']) as [$causa, $slad]) {
                $tipo = NormalizadorPjud::coincidenciaNumeroAnio($pjud, $slad);

                if ($tipo === null || ($tipo === NormalizadorPjud::COINCIDENCIA_PARCIAL && ! $permitirParcial)) {
                    continue;
                }

                $huella = hash('sha256', implode('|', [
                    $causa->id,
                    $corte->codigo,
                    $entrada->tipoTablaCodigo,
                    $entrada->fecha->toDateString(),
                    $entrada->salaNumero,
                    $pjud['numero'].'-'.($pjud['anio'] ?? ''),
                ]));

                $programacion = PjudProgramacion::withTrashed()->createOrFirst(['huella' => $huella], [
                    'causa_id' => $causa->id,
                    'tribunal_pjud_codigo' => $corte->codigo,
                    'tribunal_nombre' => mb_substr($corte->nombre, 0, 180),
                    'tipo_tabla_codigo' => mb_substr($entrada->tipoTablaCodigo, 0, 10),
                    'tipo_tabla' => mb_substr($entrada->tipoTabla, 0, 80),
                    'fecha_vista' => $entrada->fecha->toDateString(),
                    'sala_numero' => mb_substr($entrada->salaNumero, 0, 10),
                    'sala' => mb_substr($entrada->sala, 0, 80),
                    'relator' => $entrada->relator === null ? null : mb_substr($entrada->relator, 0, 180),
                    'numero_orden' => $entrada->numeroOrden,
                    'numero_ingreso' => mb_substr($entrada->numeroIngreso, 0, 100),
                    'tipo_coincidencia' => $tipo,
                    'caratulado' => $entrada->caratulado === null ? null : mb_substr($entrada->caratulado, 0, 255),
                    'detectada_en' => now(),
                ]);

                if ($programacion->wasRecentlyCreated) {
                    $nuevas[] = $programacion;
                }
            }
        }

        return $nuevas;
    }

    /**
     * Causas consultables de la Corte, agrupadas por número (sin prefijo ni año).
     *
     * @return Collection<array-key, Collection<int, array{0: Causa, 1: array{numero: string, anio: ?string}}>>
     */
    private function causasDeLaCorte(TribunalPjud $corte): Collection
    {
        $causas = Causa::query()
            ->select(['id', 'nombre', 'numero_causa', 'juzgado_id', 'responsable_id'])
            ->whereHas('juzgado', fn ($query) => $query->where('tribunal_pjud_codigo', $corte->codigo))
            ->consultablesPjud((array) config('pjud.programacion.estados_causa_consultables', ['VIGENTE']))
            ->get();

        $porNumero = collect();

        foreach ($causas as $causa) {
            $componentes = NormalizadorPjud::numeroYAnio($causa->numero_causa);

            if ($componentes !== null) {
                $porNumero->put($componentes['numero'], $porNumero->get($componentes['numero'], collect())->push([$causa, $componentes]));
            }
        }

        return $porNumero;
    }
}
