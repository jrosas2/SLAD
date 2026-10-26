<?php

namespace App\Services\Pjud;

use App\Models\Causa;
use App\Models\PjudMovimiento;
use App\Models\TribunalPjud;
use App\Support\Pjud\NormalizadorPjud;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cruza el Estado Diario de un tribunal con las causas de SLAD.
 *
 * Una fila del listado corresponde a una causa cuando calzan los DOS datos
 * clave:
 *   1. Número de ingreso = causas.numero_causa, aunque en SLAD esté incompleto:
 *      exacta "Ordinaria-5329-2025", compatible "O-5329-2025" / "5329-2025",
 *      parcial "5329" (sin año; el aviso pide verificar). Ver NormalizadorPjud::coincidencia().
 *   2. Nombre del tribunal = tribunal PJUD vinculado al juzgado de la causa.
 *      Si la fila no trae columna de tribunal, se usa el tribunal consultado.
 */
class DetectorMovimientosPjud
{
    /**
     * @param  list<EntradaEstadoDiario>  $entradas
     * @return list<array{0: PjudMovimiento, 1: EntradaEstadoDiario}> Sólo los movimientos NUEVOS, con su fila del listado.
     */
    public function registrar(TribunalPjud $tribunal, CarbonImmutable $fecha, array $entradas): array
    {
        if ($entradas === []) {
            return [];
        }

        $equivalencias = (array) config('pjud.equivalencias_nombre', []);
        $permitirParcial = (bool) config('pjud.coincidencia_parcial', true);
        $causasPorNumero = $this->causasDelTribunal($tribunal);

        if ($causasPorNumero->isEmpty()) {
            return [];
        }

        $nuevos = [];

        foreach ($entradas as $entrada) {
            $pjud = NormalizadorPjud::componentes($entrada->numeroIngreso);

            if ($pjud === null || ! $causasPorNumero->has($pjud['numero'])) {
                continue;
            }

            foreach ($causasPorNumero->get($pjud['numero']) as [$causa, $slad]) {
                // Dato clave 1: número de ingreso (completo o incompleto en SLAD).
                $tipo = NormalizadorPjud::coincidencia($pjud, $slad);

                if ($tipo === null || ($tipo === NormalizadorPjud::COINCIDENCIA_PARCIAL && ! $permitirParcial)) {
                    continue;
                }

                // Dato clave 2: tribunal.
                if (! $this->tribunalCoincide($entrada, $tribunal, $causa, $equivalencias)) {
                    continue;
                }

                $huella = hash('sha256', implode('|', [
                    $causa->id,
                    $tribunal->codigo,
                    $fecha->toDateString(),
                    NormalizadorPjud::numeroIngreso($entrada->numeroIngreso),
                    NormalizadorPjud::nombreTribunal($entrada->tramite),
                ]));

                $movimiento = PjudMovimiento::query()->createOrFirst(['huella' => $huella], [
                    'causa_id' => $causa->id,
                    'tribunal_pjud_codigo' => $tribunal->codigo,
                    'fecha_estado_diario' => $fecha->toDateString(),
                    'numero_ingreso' => mb_substr($entrada->numeroIngreso, 0, 100),
                    'tipo_coincidencia' => $tipo,
                    'tribunal_nombre' => mb_substr($entrada->tribunal ?? $tribunal->nombre, 0, 180),
                    'caratulado' => $entrada->caratulado === null ? null : mb_substr($entrada->caratulado, 0, 255),
                    'tramite' => $entrada->tramite,
                ]);

                if ($movimiento->wasRecentlyCreated) {
                    $nuevos[] = [$movimiento, $entrada];
                }
            }
        }

        return $nuevos;
    }

    /**
     * Causas vigentes del tribunal, agrupadas por número (sin letra ni año),
     * para que calcen tanto "Ordinaria-5329-2025" como "5329".
     *
     * @return Collection<array-key, Collection<int, array{0: Causa, 1: array{letra: ?string, numero: string, anio: ?string}}>>
     */
    private function causasDelTribunal(TribunalPjud $tribunal): Collection
    {
        $causas = Causa::query()
            ->select(['id', 'nombre', 'numero_causa', 'juzgado_id', 'responsable_id'])
            ->with('juzgado:id,nombre,tribunal_pjud_codigo')
            ->whereHas('juzgado', fn ($query) => $query->where('tribunal_pjud_codigo', $tribunal->codigo))
            ->consultablesPjud() // sólo vigentes: una causa cerrada nunca genera aviso
            ->get();

        $porNumero = collect();

        foreach ($causas as $causa) {
            $componentes = NormalizadorPjud::componentes($causa->numero_causa);

            if ($componentes !== null) {
                $porNumero->put($componentes['numero'], $porNumero->get($componentes['numero'], collect())->push([$causa, $componentes]));
            }
        }

        return $porNumero;
    }

    /**
     * @param  array<string, string>  $equivalencias
     */
    private function tribunalCoincide(EntradaEstadoDiario $entrada, TribunalPjud $tribunal, Causa $causa, array $equivalencias): bool
    {
        if (blank($entrada->tribunal)) {
            return true; // El listado es del tribunal consultado.
        }

        $nombreFila = NormalizadorPjud::nombreTribunal($entrada->tribunal, $equivalencias);

        return in_array($nombreFila, [
            $tribunal->nombre_normalizado,
            NormalizadorPjud::nombreTribunal($causa->juzgado?->nombre, $equivalencias),
        ], true);
    }
}
