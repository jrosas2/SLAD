<?php

namespace App\Services;

use App\Enums\EstadoRecordatorio;
use App\Enums\TipoMovimientoFinanciero;
use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\EstadoCausa;
use App\Models\Juzgado;
use App\Models\MovimientoFinanciero;
use App\Models\Recordatorio;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class DashboardStatsService
{
    /** @return Collection<int, Recordatorio> */
    public function proximosRecordatorios(User $user): Collection
    {
        return Recordatorio::query()
            ->select(['id', 'causa_id', 'titulo', 'fecha_hora', 'estado'])
            ->with('causa:id,numero_causa,nombre')
            ->whereHas('causa')
            ->where('user_id', $user->id)
            ->where('estado', EstadoRecordatorio::Pendiente)
            ->where('fecha_hora', '>=', now())
            ->orderBy('fecha_hora')
            ->limit(6)
            ->get();
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters, ?User $viewer = null): array
    {
        return [
            'resumen' => $this->resumen($filters, $viewer),
            'causasPorMateria' => $this->causasPorMateria($filters, $viewer),
            'causasPorEstado' => $this->causasPorEstado($filters, $viewer),
            'causasPorEstadoProcesal' => $this->causasPorEstadoProcesal($filters, $viewer),
            'causasPorDireccion' => $this->causasPorDireccion($filters, $viewer),
            'causasPorResponsable' => $this->causasPorResponsable($filters, $viewer),
            'causasPorMes' => $this->causasPorMes($filters, $viewer),
            'variacionCausasAnual' => $this->variacionCausasAnual($filters, $viewer),
            'finanzasPorMes' => $this->finanzasPorMes($filters, $viewer),
            'actividadReciente' => $this->actividadReciente($filters, $viewer),
            'causasSinActuaciones' => $this->causasSinActuaciones($filters, $viewer),
        ];
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return array{totalCausas: int, causasVigentes: int, causasCerradas: int, montoDemandado: int, totalIngresos: int, totalEgresos: int, saldo: int, sinResponsable: int, sinEstadoProcesal: int, sinActuaciones: int}
     */
    public function resumen(array $filters, ?User $viewer = null): array
    {
        $estadoIds = EstadoCausa::query()
            ->whereIn('nombre', ['VIGENTE', 'CERRADA'])
            ->pluck('id', 'nombre');
        $vigenteId = (int) $estadoIds->get('VIGENTE', 0);
        $cerradaId = (int) $estadoIds->get('CERRADA', 0);

        $causas = $this->causasQuery($filters, true, $viewer);
        $resumenCausas = (clone $causas)
            ->toBase()
            ->selectRaw('COUNT(*) AS total_causas')
            ->selectRaw('COALESCE(SUM(monto_demandado), 0) AS monto_demandado')
            ->selectRaw('SUM(CASE WHEN estado_causa_id = ? THEN 1 ELSE 0 END) AS causas_vigentes', [$vigenteId])
            ->selectRaw('SUM(CASE WHEN estado_causa_id = ? THEN 1 ELSE 0 END) AS causas_cerradas', [$cerradaId])
            ->selectRaw('SUM(CASE WHEN responsable_id IS NULL THEN 1 ELSE 0 END) AS sin_responsable')
            ->selectRaw('SUM(CASE WHEN estado_procesal_id IS NULL THEN 1 ELSE 0 END) AS sin_estado_procesal')
            ->first();

        $resumenFinanciero = $this->resumenFinanciero($filters, $viewer);

        return [
            'totalCausas' => (int) $resumenCausas->total_causas,
            'causasVigentes' => (int) $resumenCausas->causas_vigentes,
            'causasCerradas' => (int) $resumenCausas->causas_cerradas,
            'montoDemandado' => (int) $resumenCausas->monto_demandado,
            'totalIngresos' => $resumenFinanciero['totalIngresos'],
            'totalEgresos' => $resumenFinanciero['totalEgresos'],
            'saldo' => $resumenFinanciero['saldo'],
            'sinResponsable' => (int) $resumenCausas->sin_responsable,
            'sinEstadoProcesal' => (int) $resumenCausas->sin_estado_procesal,
            'sinActuaciones' => (clone $causas)->whereDoesntHave('actuaciones')->count(),
        ];
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{id: int<0, max>, nombre: string, cantidad: int}>
     */
    public function causasPorMateria(array $filters, ?User $viewer = null): array
    {
        return array_values($this->causasQuery($filters, true, $viewer)
            ->select('materia_id')
            ->selectRaw('COUNT(*) AS cantidad')
            ->with('materia:id,nombre')
            ->groupBy('materia_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(fn (Causa $causa): array => [
                'id' => $causa->materia_id,
                'nombre' => $causa->materia->nombre,
                'cantidad' => (int) $causa->getAttribute('cantidad'),
            ])->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{id: int<0, max>|null, nombre: string, cantidad: int}>
     */
    public function causasPorEstado(array $filters, ?User $viewer = null): array
    {
        return array_values($this->causasQuery($filters, true, $viewer)
            ->select('estado_causa_id')
            ->selectRaw('COUNT(*) AS cantidad')
            ->with('estadoCausa:id,nombre')
            ->groupBy('estado_causa_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(fn (Causa $causa): array => [
                'id' => $causa->estado_causa_id,
                'nombre' => $causa->estado_causa_id === null ? 'SIN ESTADO' : $causa->estadoCausa->nombre,
                'cantidad' => (int) $causa->getAttribute('cantidad'),
            ])->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{id: int<0, max>|null, nombre: string, cantidad: int}>
     */
    public function causasPorEstadoProcesal(array $filters, ?User $viewer = null): array
    {
        return array_values($this->causasQuery($filters, true, $viewer)
            ->select('estado_procesal_id')
            ->selectRaw('COUNT(*) AS cantidad')
            ->with('estadoProcesal:id,nombre')
            ->groupBy('estado_procesal_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(fn (Causa $causa): array => [
                'id' => $causa->estado_procesal_id,
                'nombre' => $causa->estado_procesal_id === null ? 'SIN ESTADO PROCESAL' : $causa->estadoProcesal->nombre,
                'cantidad' => (int) $causa->getAttribute('cantidad'),
            ])->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{id: int<0, max>|null, nombre: string, cantidad: int}>
     */
    public function causasPorResponsable(array $filters, ?User $viewer = null): array
    {
        return array_values($this->causasQuery($filters, true, $viewer)
            ->select('responsable_id')
            ->selectRaw('COUNT(*) AS cantidad')
            ->with('responsable:id,codigo,name')
            ->groupBy('responsable_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(function (Causa $causa): array {
                $responsable = $causa->responsable;
                $nombre = $responsable === null
                    ? 'SIN RESPONSABLE'
                    : $responsable->etiquetaResponsable();

                return [
                    'id' => $causa->responsable_id,
                    'nombre' => $nombre,
                    'cantidad' => (int) $causa->getAttribute('cantidad'),
                ];
            })->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{id: int<0, max>|null, nombre: string, cantidad: int}>
     */
    public function causasPorDireccion(array $filters, ?User $viewer = null): array
    {
        return array_values($this->causasQuery($filters, true, $viewer)
            ->select('direccion_id')
            ->selectRaw('COUNT(*) AS cantidad')
            ->with('direccion:id,nombre')
            ->groupBy('direccion_id')
            ->orderByDesc('cantidad')
            ->get()
            ->map(fn (Causa $causa): array => [
                'id' => $causa->direccion_id,
                'nombre' => $causa->direccion_id === null ? 'SIN DIRECCIÓN' : $causa->direccion->nombre,
                'cantidad' => (int) $causa->getAttribute('cantidad'),
            ])->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{mes: int, nombre: string, cantidad: int}>
     */
    public function causasPorMes(array $filters, ?User $viewer = null): array
    {
        $query = $this->causasQuery($filters, true, $viewer)->whereNotNull('fecha_ingreso');

        if ((new Causa)->getConnection()->getDriverName() === 'sqlite') {
            $query->selectRaw("CAST(strftime('%m', fecha_ingreso) AS INTEGER) AS mes")
                ->groupByRaw("CAST(strftime('%m', fecha_ingreso) AS INTEGER)");
        } else {
            $query->selectRaw('MONTH(fecha_ingreso) AS mes')
                ->groupByRaw('MONTH(fecha_ingreso)');
        }

        $totals = $query->selectRaw('COUNT(*) AS cantidad')->pluck('cantidad', 'mes');

        return array_values($this->months()->map(fn (string $nombre, int $month): array => [
            'mes' => $month,
            'nombre' => $nombre,
            'cantidad' => (int) $totals->get($month, 0),
        ])->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return array{anioActual: int|null, anioAnterior: int|null, totalActual: int, totalAnterior: int, porcentaje: float|null}
     */
    public function variacionCausasAnual(array $filters, ?User $viewer = null): array
    {
        $year = $filters['year'];

        if ($year === null) {
            return [
                'anioActual' => null,
                'anioAnterior' => null,
                'totalActual' => 0,
                'totalAnterior' => 0,
                'porcentaje' => null,
            ];
        }

        $previousYear = $year - 1;
        $query = $this->causasQuery($filters, false, $viewer)->toBase();

        if ((new Causa)->getConnection()->getDriverName() === 'sqlite') {
            $yearExpression = "CAST(strftime('%Y', fecha_ingreso) AS INTEGER)";
        } else {
            $yearExpression = 'YEAR(fecha_ingreso)';
        }

        $totals = $query
            ->selectRaw("SUM(CASE WHEN {$yearExpression} = ? THEN 1 ELSE 0 END) AS total_actual", [$year])
            ->selectRaw("SUM(CASE WHEN {$yearExpression} = ? THEN 1 ELSE 0 END) AS total_anterior", [$previousYear])
            ->first();
        $currentTotal = (int) $totals->total_actual;
        $previousTotal = (int) $totals->total_anterior;
        $percentage = match (true) {
            $previousTotal > 0 => round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1),
            $currentTotal === 0 => 0.0,
            default => null,
        };

        return [
            'anioActual' => $year,
            'anioAnterior' => $previousYear,
            'totalActual' => $currentTotal,
            'totalAnterior' => $previousTotal,
            'porcentaje' => $percentage,
        ];
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return list<array{mes: int, nombre: string, ingresos: int, egresos: int}>
     */
    public function finanzasPorMes(array $filters, ?User $viewer = null): array
    {
        $query = $this->movimientosQuery($filters, $viewer)->whereNotNull('fecha');

        if ((new MovimientoFinanciero)->getConnection()->getDriverName() === 'sqlite') {
            $query->selectRaw("CAST(strftime('%m', fecha) AS INTEGER) AS mes")
                ->groupByRaw("CAST(strftime('%m', fecha) AS INTEGER)");
        } else {
            $query->selectRaw('MONTH(fecha) AS mes')
                ->groupByRaw('MONTH(fecha)');
        }

        $totals = $query
            ->selectRaw('COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) AS ingresos', [TipoMovimientoFinanciero::Ingreso->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) AS egresos', [TipoMovimientoFinanciero::Egreso->value])
            ->get()
            ->keyBy('mes');

        return array_values($this->months()->map(function (string $nombre, int $month) use ($totals): array {
            $total = $totals->get($month);

            return [
                'mes' => $month,
                'nombre' => $nombre,
                'ingresos' => (int) ($total?->getAttribute('ingresos') ?? 0),
                'egresos' => (int) ($total?->getAttribute('egresos') ?? 0),
            ];
        })->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return Collection<int, Actuacion>
     */
    public function actividadReciente(array $filters, ?User $viewer = null): Collection
    {
        return Actuacion::query()
            ->select(['id', 'causa_id', 'fecha', 'estado_procesal_id', 'descripcion', 'created_by'])
            ->with([
                'causa:id,numero_causa,nombre',
                'estadoProcesal:id,nombre',
                'creador:id,name',
            ])
            ->whereIn('causa_id', $this->causasQuery($filters, false, $viewer)->select('id'))
            ->when($filters['year'] !== null, fn (Builder $query) => $query->whereYear('fecha', $filters['year']))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return Collection<int, Causa>
     */
    public function causasSinActuaciones(array $filters, ?User $viewer = null): Collection
    {
        return $this->causasQuery($filters, true, $viewer)
            ->select(['id', 'numero_causa', 'nombre', 'fecha_ingreso', 'responsable_id'])
            ->with('responsable:id,codigo,name')
            ->whereDoesntHave('actuaciones')
            ->orderBy('fecha_ingreso')
            ->orderBy('id')
            ->limit(6)
            ->get();
    }

    /** @return list<int> */
    public function availableYears(?User $viewer = null): array
    {
        $years = collect([now()->year]);

        $years = $years
            ->merge($this->causaYears($viewer))
            ->merge($this->actuacionYears($viewer))
            ->merge($this->movimientoYears($viewer));

        return array_values($years->unique()->sortDesc()->values()->all());
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return array{totalIngresos: int, totalEgresos: int, saldo: int}
     */
    private function resumenFinanciero(array $filters, ?User $viewer): array
    {
        $totals = $this->movimientosQuery($filters, $viewer)
            ->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) AS ingresos', [TipoMovimientoFinanciero::Ingreso->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN tipo = ? THEN monto ELSE 0 END), 0) AS egresos', [TipoMovimientoFinanciero::Egreso->value])
            ->first();
        $ingresos = (int) $totals->ingresos;
        $egresos = (int) $totals->egresos;

        return [
            'totalIngresos' => $ingresos,
            'totalEgresos' => $egresos,
            'saldo' => $ingresos - $egresos,
        ];
    }

    /**
     * El filtro anual de causas usa exclusivamente fecha_ingreso. Las causas sin esa fecha
     * quedan fuera al seleccionar un año; no se mezcla silenciosamente con fecha_causa.
     *
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return Builder<Causa>
     */
    private function causasQuery(array $filters, bool $applyYear = true, ?User $viewer = null): Builder
    {
        return Causa::query()
            ->when($viewer !== null, fn (Builder $query) => $query->visibleFor($viewer))
            ->when($applyYear && $filters['year'] !== null, fn (Builder $query) => $query->whereYear('fecha_ingreso', $filters['year']))
            ->when($filters['materiaId'] !== null, fn (Builder $query) => $query->where('materia_id', $filters['materiaId']))
            ->when($filters['direccionId'] !== null, fn (Builder $query) => $query->where('direccion_id', $filters['direccionId']))
            ->when($filters['responsableId'] !== null, fn (Builder $query) => $query->where('responsable_id', $filters['responsableId']))
            ->when($filters['estadoCausaId'] !== null, fn (Builder $query) => $query->where('estado_causa_id', $filters['estadoCausaId']))
            ->when($filters['estadoProcesalId'] !== null, fn (Builder $query) => $query->where('estado_procesal_id', $filters['estadoProcesalId']))
            ->when($filters['juzgadoId'] !== null, fn (Builder $query) => $query->where('juzgado_id', $filters['juzgadoId']))
            ->when($filters['ciudadId'] !== null, fn (Builder $query) => $query->whereIn(
                'juzgado_id',
                Juzgado::query()->select('id')->where('ciudad_id', $filters['ciudadId']),
            ));
    }

    /**
     * @param  array{year: int|null, materiaId: int|null, direccionId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}  $filters
     * @return Builder<MovimientoFinanciero>
     */
    private function movimientosQuery(array $filters, ?User $viewer): Builder
    {
        return MovimientoFinanciero::query()
            ->whereIn('causa_id', $this->causasQuery($filters, false, $viewer)->select('id'))
            ->when($filters['year'] !== null, fn (Builder $query) => $query->whereYear('fecha', $filters['year']));
    }

    /** @return SupportCollection<int, string> */
    private function months(): SupportCollection
    {
        return collect([
            1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN',
            7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC',
        ]);
    }

    /** @return SupportCollection<int, int> */
    private function causaYears(?User $viewer): SupportCollection
    {
        $query = Causa::query()
            ->when($viewer !== null, fn (Builder $query) => $query->visibleFor($viewer))
            ->whereNotNull('fecha_ingreso');

        if ((new Causa)->getConnection()->getDriverName() === 'sqlite') {
            $query->selectRaw("CAST(strftime('%Y', fecha_ingreso) AS INTEGER) AS anio")
                ->groupByRaw("CAST(strftime('%Y', fecha_ingreso) AS INTEGER)");
        } else {
            $query->selectRaw('YEAR(fecha_ingreso) AS anio')->groupByRaw('YEAR(fecha_ingreso)');
        }

        return $query->pluck('anio')->map(fn (mixed $year): int => (int) $year);
    }

    /** @return SupportCollection<int, int> */
    private function actuacionYears(?User $viewer): SupportCollection
    {
        $query = Actuacion::query()
            ->whereIn('causa_id', $this->causasQuery(['year' => null, 'materiaId' => null, 'direccionId' => null, 'responsableId' => null, 'estadoCausaId' => null, 'estadoProcesalId' => null, 'ciudadId' => null, 'juzgadoId' => null], false, $viewer)->select('id'))
            ->whereNotNull('fecha');

        if ((new Actuacion)->getConnection()->getDriverName() === 'sqlite') {
            $query->selectRaw("CAST(strftime('%Y', fecha) AS INTEGER) AS anio")
                ->groupByRaw("CAST(strftime('%Y', fecha) AS INTEGER)");
        } else {
            $query->selectRaw('YEAR(fecha) AS anio')->groupByRaw('YEAR(fecha)');
        }

        return $query->pluck('anio')->map(fn (mixed $year): int => (int) $year);
    }

    /** @return SupportCollection<int, int> */
    private function movimientoYears(?User $viewer): SupportCollection
    {
        $query = MovimientoFinanciero::query()
            ->whereIn('causa_id', $this->causasQuery(['year' => null, 'materiaId' => null, 'direccionId' => null, 'responsableId' => null, 'estadoCausaId' => null, 'estadoProcesalId' => null, 'ciudadId' => null, 'juzgadoId' => null], false, $viewer)->select('id'))
            ->whereNotNull('fecha');

        if ((new MovimientoFinanciero)->getConnection()->getDriverName() === 'sqlite') {
            $query->selectRaw("CAST(strftime('%Y', fecha) AS INTEGER) AS anio")
                ->groupByRaw("CAST(strftime('%Y', fecha) AS INTEGER)");
        } else {
            $query->selectRaw('YEAR(fecha) AS anio')->groupByRaw('YEAR(fecha)');
        }

        return $query->pluck('anio')->map(fn (mixed $year): int => (int) $year);
    }
}
