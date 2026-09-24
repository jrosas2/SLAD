<?php

namespace App\Services\Imports;

use App\Enums\EstadoImportacion;
use App\Enums\Permiso;
use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\Importacion;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class HistoricalImportService
{
    public function __construct(private HistoricalExcelAnalyzer $analyzer) {}

    public function import(Importacion $importacion, User $actor): Importacion
    {
        Gate::forUser($actor)->authorize(Permiso::ImportacionesEjecutar->value);

        return Cache::lock('importacion-historica-'.$importacion->hash_archivo, 300)->block(5, function () use ($importacion, $actor): Importacion {
            return $this->perform($importacion, $actor);
        });
    }

    private function perform(Importacion $importacion, User $actor): Importacion
    {
        $path = Storage::disk('local')->path($importacion->ruta_archivo);

        if (! is_file($path) || hash_file('sha256', $path) !== $importacion->hash_archivo) {
            throw new RuntimeException('El archivo almacenado no está disponible o cambió desde el análisis.');
        }

        $alreadyCompleted = Importacion::query()
            ->where('hash_archivo', $importacion->hash_archivo)
            ->whereKeyNot($importacion->id)
            ->whereIn('estado', [EstadoImportacion::Completada, EstadoImportacion::CompletadaConErrores])
            ->exists();

        if ($alreadyCompleted) {
            throw new RuntimeException('Este mismo archivo ya posee una importación completada.');
        }

        $importacion->update([
            'estado' => EstadoImportacion::Importando,
            'fecha_inicio' => now(),
            'fecha_fin' => null,
        ]);

        Log::info('Inicio de importación histórica.', ['importacion_id' => $importacion->id, 'user_id' => $actor->id, 'archivo' => $importacion->archivo]);

        try {
            $analysis = $this->analyzer->analyze($path);
            $imported = 0;
            $omitted = 0;
            $failed = 0;
            $actuations = 0;
            $income = 0;
            $expenses = 0;
            $responsibles = 0;
            $createdMatters = [];
            $createdSubmatters = [];

            foreach (array_chunk($analysis['items'], 100) as $batch) {
                foreach ($batch as $item) {
                    if (! in_array($item['resultado'], ['VÁLIDO', 'ADVERTENCIA'], true)) {
                        $omitted++;
                        $failed += $item['resultado'] === 'ERROR' ? 1 : 0;

                        continue;
                    }

                    try {
                        $created = DB::transaction(fn (): array => $this->createCause($item), attempts: 3);
                        $imported++;
                        $actuations += $created['actuaciones'];
                        $income += $created['ingresos'];
                        $expenses += $created['egresos'];
                        $responsibles += $created['responsable'] ? 1 : 0;

                        if ($created['materia'] !== null) {
                            $createdMatters[$created['materia']] = true;
                        }

                        if ($created['submateria'] !== null) {
                            $createdSubmatters[$created['submateria']] = true;
                        }
                    } catch (\Throwable $exception) {
                        $failed++;
                        $omitted++;
                        $importacion->errores()->create([
                            'fila' => $item['fila'],
                            'numero_causa' => $item['numero_causa'],
                            'campo' => null,
                            'valor_original' => null,
                            'codigo_error' => 'ERROR_IMPORTACION',
                            'mensaje' => 'No fue posible importar esta fila. Revisa los datos y vuelve a analizar el archivo.',
                            'datos_originales' => $item['data'],
                        ]);
                        Log::error('Falló una fila de importación histórica.', [
                            'importacion_id' => $importacion->id,
                            'fila' => $item['fila'],
                            'exception_class' => $exception::class,
                            'exception_message' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            $finalSummary = [
                ...$analysis['summary'],
                'causas_creadas' => $imported,
                'causas_omitidas' => $omitted,
                'actuaciones_creadas' => $actuations,
                'ingresos_creados' => $income,
                'egresos_creados' => $expenses,
                'responsables_asociados' => $responsibles,
                'catalogos_creados' => count($createdMatters) + count($createdSubmatters),
            ];

            $finalState = $failed > 0 ? EstadoImportacion::CompletadaConErrores : EstadoImportacion::Completada;
            $importacion->update([
                'estado' => $finalState,
                'total_filas' => count($analysis['items']),
                'filas_importadas' => $imported,
                'filas_omitidas' => $omitted,
                'filas_error' => $failed,
                'fecha_fin' => now(),
                'resumen' => $finalSummary,
            ]);

            Log::info('Fin de importación histórica.', ['importacion_id' => $importacion->id, 'user_id' => $actor->id, 'resultado' => $finalState->value]);

            return $importacion->refresh();
        } catch (\Throwable $exception) {
            $importacion->update([
                'estado' => EstadoImportacion::Fallida,
                'fecha_fin' => now(),
            ]);
            Log::error('Falló la importación histórica.', [
                'importacion_id' => $importacion->id,
                'user_id' => $actor->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            Storage::disk('local')->delete($importacion->ruta_archivo);
        }
    }

    /** @param array<string, mixed> $item
     * @return array{actuaciones: int, ingresos: int, egresos: int, responsable: bool, materia: string|null, submateria: string|null}
     */
    private function createCause(array $item): array
    {
        $data = $item['data'];
        $createdMatter = null;
        $createdSubmatter = null;
        if ($data['materia_id'] === null) {
            $matterName = Str::upper(Str::squish($data['materia_nombre']));
            $materia = Materia::query()->where('nombre', $matterName)->first();

            if ($materia === null) {
                $materia = Materia::query()->create(['nombre' => $matterName, 'activo' => true]);
            }
        } else {
            $materia = Materia::query()->findOrFail((int) $data['materia_id']);
        }

        if ($data['materia_id'] === null && $materia->wasRecentlyCreated) {
            $createdMatter = $materia->nombre;
        }

        $submateriaId = $data['submateria_id'];

        if ($submateriaId === null && $data['submateria_nombre'] !== null) {
            $submatterName = Str::upper(Str::squish($data['submateria_nombre']));
            $submateria = Submateria::query()
                ->whereBelongsTo($materia)
                ->where('nombre', $submatterName)
                ->first();

            if ($submateria === null) {
                $submateria = Submateria::query()->create([
                    'materia_id' => $materia->id,
                    'nombre' => $submatterName,
                    'activo' => true,
                ]);
            }
            $submateriaId = $submateria->id;

            if ($submateria->wasRecentlyCreated) {
                $createdSubmatter = $materia->nombre.' / '.$submateria->nombre;
            }
        }

        $causa = new Causa([
            'nombre' => $data['nombre'],
            'fecha_causa' => $data['fecha_causa'],
            'fecha_ingreso' => $data['fecha_ingreso'],
            'juzgado_id' => $data['juzgado_id'],
            'materia_id' => $materia->id,
            'submateria_id' => $submateriaId,
            'estado_procesal_id' => $data['estado_procesal_id'],
            'direccion_id' => $data['direccion_id'],
            'accion_id' => $data['accion_id'],
            'estado_causa_id' => $data['estado_causa_id'],
            'numero_causa' => $data['numero_causa'],
            'monto_demandado' => $data['monto_demandado'],
            'observacion_importante' => $data['observacion_importante'],
            'tiene_cotizaciones' => $data['tiene_cotizaciones'],
        ]);
        $causa->responsable_id = $data['responsable_id'];
        $causa->save();

        foreach ($data['actuaciones'] as $actuacion) {
            $causa->actuaciones()->create([
                'fecha' => $actuacion['fecha'],
                'descripcion' => $actuacion['descripcion'],
                'estado_procesal_id' => null,
                'created_by' => null,
            ]);
        }

        $income = $this->createMovement($causa, TipoMovimientoFinanciero::Ingreso, $data['ingreso']);
        $expenses = $this->createMovement($causa, TipoMovimientoFinanciero::Egreso, $data['egreso']);

        return [
            'actuaciones' => count($data['actuaciones']),
            'ingresos' => $income,
            'egresos' => $expenses,
            'responsable' => $data['responsable_id'] !== null,
            'materia' => $createdMatter,
            'submateria' => $createdSubmatter,
        ];
    }

    private function createMovement(Causa $causa, TipoMovimientoFinanciero $type, ?int $amount): int
    {
        if ($amount === null || $amount <= 0) {
            return 0;
        }

        $causa->movimientosFinancieros()->create([
            'tipo' => $type,
            'monto' => $amount,
            'fecha' => null,
            'observacion' => null,
            'created_by' => null,
        ]);

        return 1;
    }
}
