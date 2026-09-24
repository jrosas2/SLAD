<?php

namespace App\Services\Imports;

use App\Models\Accion;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class HistoricalExcelAnalyzer
{
    public function __construct(
        private HistoricalExcelReader $reader,
        private HistoricalHeaderMapper $headerMapper,
        private HistoricalDateParser $dateParser,
        private HistoricalMoneyParser $moneyParser,
        private HistoricalActuationParser $actuationParser,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(string $path): array
    {
        $metadata = $this->reader->inspect($path);
        $context = $this->context();
        $items = [];
        $issues = [];
        $seenExactKeys = [];

        foreach ($this->reader->rows($path, $metadata) as $row) {
            $item = $this->analyzeRow($row['fila'], $row['valores'], $context, $seenExactKeys);
            $items[] = $item;
            array_push($issues, ...$item['issues']);

            if ($item['duplicate_key'] !== null) {
                $seenExactKeys[$item['duplicate_key']] = true;
            }
        }

        return [
            'sheet' => $metadata['sheet'],
            'headers' => array_values(array_filter($metadata['headers'])),
            'items' => $items,
            'preview' => array_slice($items, 0, 100),
            'issues' => $issues,
            'summary' => $this->summary($items),
        ];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $materias = Materia::query()->get(['id', 'nombre']);
        $submaterias = Submateria::query()->get(['id', 'materia_id', 'nombre']);
        $ciudades = Ciudad::query()->get(['id', 'nombre']);
        $juzgados = Juzgado::query()->get(['id', 'ciudad_id', 'nombre']);

        $causas = Causa::query()
            ->select(['id', 'nombre', 'numero_causa', 'materia_id', 'juzgado_id'])
            ->with(['materia:id,nombre', 'juzgado:id,ciudad_id,nombre', 'juzgado.ciudad:id,nombre'])
            ->get();

        $exactDuplicates = [];
        $possibleDuplicates = [];

        foreach ($causas as $causa) {
            $exactDuplicates[$this->duplicateKey(
                $causa->numero_causa,
                $causa->nombre,
                $causa->materia?->nombre,
                $causa->juzgado?->nombre,
                $causa->juzgado?->ciudad?->nombre,
            )] = true;
            $possibleDuplicates[$this->possibleDuplicateKey($causa->numero_causa, $causa->nombre)] = true;
        }

        return [
            'materias' => $this->catalogMap($materias),
            'submaterias' => $submaterias->mapWithKeys(fn (Submateria $submateria): array => [
                $submateria->materia_id.'|'.$this->headerMapper->normalizeComparable($submateria->nombre) => [
                    'id' => $submateria->id,
                    'nombre' => $submateria->nombre,
                ],
            ])->all(),
            'ciudades' => $this->catalogMap($ciudades),
            'juzgados_nombre' => $juzgados->groupBy(fn (Juzgado $juzgado): string => $juzgado->ciudad_id.'|'.$this->headerMapper->normalizeComparable($juzgado->nombre))->all(),
            'estados_procesales' => $this->catalogMap(EstadoProcesal::query()->get(['id', 'nombre'])),
            'estados_causa' => $this->catalogMap(EstadoCausa::query()->get(['id', 'nombre'])),
            'acciones' => $this->catalogMap(Accion::query()->get(['id', 'nombre'])),
            'direcciones' => $this->catalogMap(Direccion::query()->get(['id', 'nombre'])),
            'users' => User::query()->whereNotNull('codigo')->get(['id', 'codigo', 'name'])->mapWithKeys(
                fn (User $user): array => [Str::upper(trim((string) $user->codigo)) => ['id' => $user->id, 'nombre' => $user->name]],
            )->all(),
            'exact_duplicates' => $exactDuplicates,
            'possible_duplicates' => $possibleDuplicates,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @param  array<string, bool>  $seenExactKeys
     * @return array<string, mixed>
     */
    private function analyzeRow(int $rowNumber, array $values, array $context, array $seenExactKeys): array
    {
        $issues = [];
        $nombre = $this->cleanText($values['nombre'] ?? null);
        $numeroCausa = $this->cleanText($values['numero_causa'] ?? null);
        $materiaName = $this->cleanText($values['materia'] ?? null);
        $submateriaName = $this->cleanText($values['submateria'] ?? null);

        if ($nombre === null) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'nombre', $values['nombre'] ?? null, 'NOMBRE_REQUERIDO', 'El nombre de la causa es obligatorio.', 'ERROR', $values);
        }

        if ($materiaName === null) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'materia', $values['materia'] ?? null, 'MATERIA_REQUERIDA', 'La materia es obligatoria.', 'ERROR', $values);
        }

        $materia = $materiaName === null ? null : ($context['materias'][$this->headerMapper->normalizeComparable($materiaName)] ?? null);
        $materiaNueva = $materiaName !== null && $materia === null;

        if ($materiaNueva) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'materia', $materiaName, 'MATERIA_NUEVA', "Se creará la materia {$materiaName} al confirmar.", 'ADVERTENCIA', $values);
        }

        $submateria = null;
        $submateriaNueva = false;

        if ($submateriaName !== null) {
            $submateriaKey = ($materia['id'] ?? 'new:'.$this->headerMapper->normalizeComparable($materiaName)).'|'.$this->headerMapper->normalizeComparable($submateriaName);
            $submateria = $context['submaterias'][$submateriaKey] ?? null;
            $submateriaNueva = $submateria === null;

            if ($submateriaNueva) {
                $this->issue($issues, $rowNumber, $numeroCausa, 'submateria', $submateriaName, 'SUBMATERIA_NUEVA', "Se creará la submateria {$submateriaName} al confirmar.", 'ADVERTENCIA', $values);
            }
        }

        $ciudadName = $this->cleanText($values['ciudad'] ?? null);
        $ciudad = $ciudadName === null ? null : ($context['ciudades'][$this->headerMapper->normalizeComparable($ciudadName)] ?? null);

        if ($ciudadName !== null && $ciudad === null) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'ciudad', $ciudadName, 'CIUDAD_NO_ENCONTRADA', "Ciudad no encontrada: {$ciudadName}. La causa quedará sin juzgado.", 'ADVERTENCIA', $values);
        }

        $juzgado = $this->resolveJuzgado($values['juzgado'] ?? null, $ciudad, $context, $issues, $rowNumber, $numeroCausa, $values);
        $estadoProcesal = $this->resolveOptionalCatalog('estado_procesal', $values['estado_procesal'] ?? null, $context['estados_procesales'], $issues, $rowNumber, $numeroCausa, $values);
        $estadoCausa = $this->resolveOptionalCatalog('estado_causa', $values['estado_causa'] ?? null, $context['estados_causa'], $issues, $rowNumber, $numeroCausa, $values);
        $accion = $this->resolveOptionalCatalog('accion', $values['accion'] ?? null, $context['acciones'], $issues, $rowNumber, $numeroCausa, $values);
        $direccion = $this->resolveOptionalCatalog('direccion', $values['direccion'] ?? null, $context['direcciones'], $issues, $rowNumber, $numeroCausa, $values);

        $codigoResponsable = blank($values['responsable_codigo'] ?? null) ? null : Str::upper(trim((string) $values['responsable_codigo']));
        $responsable = $codigoResponsable === null ? null : ($context['users'][$codigoResponsable] ?? null);

        if ($codigoResponsable !== null && $responsable === null) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'responsable_codigo', $codigoResponsable, 'RESPONSABLE_NO_ENCONTRADO', "Responsable no encontrado: {$codigoResponsable}.", 'ADVERTENCIA', $values);
        }

        $fechaCausa = $this->parseDate($values['fecha_causa'] ?? null, 'fecha_causa', $issues, $rowNumber, $numeroCausa, $values);
        $fechaIngreso = $this->parseDate($values['fecha_ingreso'] ?? null, 'fecha_ingreso', $issues, $rowNumber, $numeroCausa, $values);
        $montoDemandado = $this->parseMoney($values['monto_demandado'] ?? null, 'monto_demandado', $issues, $rowNumber, $numeroCausa, $values);
        $ingreso = $this->parseMoney($values['ingreso'] ?? null, 'ingreso', $issues, $rowNumber, $numeroCausa, $values);
        $egreso = $this->parseMoney($values['egreso'] ?? null, 'egreso', $issues, $rowNumber, $numeroCausa, $values);
        $cotizaciones = $this->parseCotizaciones($values['tiene_cotizaciones'] ?? null, $issues, $rowNumber, $numeroCausa, $values);
        $actuaciones = $this->actuationParser->parse($this->preservedText($values['observacion_causa'] ?? null));

        foreach ($actuaciones['advertencias'] as $warning) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'observacion_causa', $values['observacion_causa'] ?? null, 'ACTUACIONES_NO_SEPARABLES', $warning, 'ADVERTENCIA', $values);
        }

        $duplicateKey = $this->duplicateKey($numeroCausa, $nombre, $materiaName, $juzgado['nombre'] ?? null, $ciudadName);
        $possibleKey = $this->possibleDuplicateKey($numeroCausa, $nombre);
        $isDuplicate = isset($context['exact_duplicates'][$duplicateKey]) || isset($seenExactKeys[$duplicateKey]);
        $isPossibleDuplicate = ! $isDuplicate && $possibleKey !== '|' && isset($context['possible_duplicates'][$possibleKey]);

        if ($isDuplicate) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'numero_causa', $numeroCausa, 'DUPLICADO', 'Ya existe una causa con la misma combinación de datos.', 'ADVERTENCIA', $values);
        } elseif ($isPossibleDuplicate) {
            $this->issue($issues, $rowNumber, $numeroCausa, 'numero_causa', $numeroCausa, 'POSIBLE_DUPLICADO', 'Existe una causa similar; la fila no se importará automáticamente.', 'ADVERTENCIA', $values);
        }

        $hasErrors = collect($issues)->contains('nivel', 'ERROR');
        $hasWarnings = $issues !== [];
        $result = $hasErrors ? 'ERROR' : ($isDuplicate ? 'DUPLICADO' : ($isPossibleDuplicate ? 'POSIBLE DUPLICADO' : ($hasWarnings ? 'ADVERTENCIA' : 'VÁLIDO')));

        return [
            'fila' => $rowNumber,
            'numero_causa' => $numeroCausa,
            'nombre' => $nombre,
            'materia' => $materiaName,
            'submateria' => $submateriaName,
            'responsable' => $responsable['nombre'] ?? $codigoResponsable,
            'estado' => $estadoCausa['nombre'] ?? $this->cleanText($values['estado_causa'] ?? null),
            'monto_demandado' => $montoDemandado,
            'actuaciones_detectadas' => count($actuaciones['items']),
            'ingreso' => $ingreso,
            'egreso' => $egreso,
            'resultado' => $result,
            'issues' => $issues,
            'duplicate_key' => $duplicateKey,
            'data' => [
                'nombre' => $nombre,
                'fecha_causa' => $fechaCausa,
                'fecha_ingreso' => $fechaIngreso,
                'juzgado_id' => $juzgado['id'] ?? null,
                'materia_id' => $materia['id'] ?? null,
                'materia_nombre' => $materiaName,
                'materia_nueva' => $materiaNueva,
                'submateria_id' => $submateria['id'] ?? null,
                'submateria_nombre' => $submateriaName,
                'submateria_nueva' => $submateriaNueva,
                'estado_procesal_id' => $estadoProcesal['id'] ?? null,
                'direccion_id' => $direccion['id'] ?? null,
                'responsable_id' => $responsable['id'] ?? null,
                'accion_id' => $accion['id'] ?? null,
                'estado_causa_id' => $estadoCausa['id'] ?? null,
                'numero_causa' => $numeroCausa,
                'monto_demandado' => $montoDemandado,
                'observacion_importante' => $this->preservedText($values['observacion_importante'] ?? null),
                'tiene_cotizaciones' => $cotizaciones,
                'actuaciones' => $actuaciones['items'],
                'ingreso' => $ingreso,
                'egreso' => $egreso,
            ],
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return array<string, array{id: int, nombre: string}>
     */
    private function catalogMap(Collection $models): array
    {
        return $models->mapWithKeys(fn ($model): array => [
            $this->headerMapper->normalizeComparable((string) $model->getAttribute('nombre')) => [
                'id' => (int) $model->getKey(),
                'nombre' => (string) $model->getAttribute('nombre'),
            ],
        ])->all();
    }

    /** @param array<string, mixed>|null $ciudad
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $original
     * @return array<string, mixed>|null
     */
    private function resolveJuzgado(mixed $value, ?array $ciudad, array $context, array &$issues, int $row, ?string $number, array $original): ?array
    {
        if (blank($value)) {
            return null;
        }

        if ($ciudad === null) {
            $this->issue($issues, $row, $number, 'juzgado', $value, 'JUZGADO_SIN_CIUDAD', 'No se puede resolver el juzgado sin una ciudad reconocida.', 'ADVERTENCIA', $original);

            return null;
        }

        $text = $this->cleanText($value);
        $key = $ciudad['id'].'|'.$this->headerMapper->normalizeComparable($text);
        $matches = $context['juzgados_nombre'][$key] ?? [];

        if (count($matches) !== 1) {
            $message = count($matches) > 1 ? 'El juzgado es ambiguo para la ciudad indicada.' : 'No se encontró un juzgado inequívoco para la ciudad indicada.';
            $this->issue($issues, $row, $number, 'juzgado', $value, 'JUZGADO_AMBIGUO', $message, 'ADVERTENCIA', $original);

            return null;
        }

        $juzgado = $matches->first();

        return ['id' => $juzgado->id, 'nombre' => $juzgado->nombre];
    }

    /** @param array<string, array{id: int, nombre: string}> $catalog
     * @param  list<array<string, mixed>>  $issues
     * @param  array<string, mixed>  $original
     * @return array{id: int, nombre: string}|null
     */
    private function resolveOptionalCatalog(string $field, mixed $value, array $catalog, array &$issues, int $row, ?string $number, array $original): ?array
    {
        $text = $this->cleanText($value);

        if ($text === null) {
            return null;
        }

        $match = $catalog[$this->headerMapper->normalizeComparable($text)] ?? null;

        if ($match === null) {
            $this->issue($issues, $row, $number, $field, $value, Str::upper($field).'_NO_ENCONTRADO', "No se encontró {$field}: {$text}.", 'ADVERTENCIA', $original);
        }

        return $match;
    }

    /** @param list<array<string, mixed>> $issues
     * @param  array<string, mixed>  $original
     */
    private function parseDate(mixed $value, string $field, array &$issues, int $row, ?string $number, array $original): ?string
    {
        try {
            return $this->dateParser->parse($value)?->toDateString();
        } catch (InvalidArgumentException $exception) {
            $this->issue($issues, $row, $number, $field, $value, 'FECHA_INVALIDA', $exception->getMessage(), 'ERROR', $original);

            return null;
        }
    }

    /** @param list<array<string, mixed>> $issues
     * @param  array<string, mixed>  $original
     */
    private function parseMoney(mixed $value, string $field, array &$issues, int $row, ?string $number, array $original): ?int
    {
        try {
            return $this->moneyParser->parse($value);
        } catch (InvalidArgumentException $exception) {
            $this->issue($issues, $row, $number, $field, $value, 'MONTO_INVALIDO', $exception->getMessage(), 'ERROR', $original);

            return null;
        }
    }

    /** @param list<array<string, mixed>> $issues
     * @param  array<string, mixed>  $original
     */
    private function parseCotizaciones(mixed $value, array &$issues, int $row, ?string $number, array $original): ?bool
    {
        if (blank($value)) {
            return null;
        }

        $normalized = $this->headerMapper->normalize((string) $value);

        if (in_array($normalized, ['CON', 'SI', 'CON COTIZACIONES'], true)) {
            return true;
        }

        if (in_array($normalized, ['SIN', 'NO', 'SIN COTIZACIONES'], true)) {
            return false;
        }

        $this->issue($issues, $row, $number, 'tiene_cotizaciones', $value, 'COTIZACIONES_DESCONOCIDAS', 'El valor de cotizaciones no es reconocido; se conservará como nulo.', 'ADVERTENCIA', $original);

        return null;
    }

    /** @param list<array<string, mixed>> $issues
     * @param  array<string, mixed>  $original
     */
    private function issue(array &$issues, int $row, ?string $number, string $field, mixed $value, string $code, string $message, string $level, array $original): void
    {
        $issues[] = [
            'fila' => $row,
            'numero_causa' => $number,
            'campo' => $field,
            'valor_original' => is_scalar($value) ? (string) $value : null,
            'codigo_error' => $code,
            'mensaje' => $message,
            'nivel' => $level,
            'datos_originales' => $original,
        ];
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function summary(array $items): array
    {
        $collection = collect($items);
        $newMatters = $collection->where('data.materia_nueva', true)->pluck('materia')->filter()->unique()->values()->all();
        $newSubmatters = $collection->where('data.submateria_nueva', true)->map(fn (array $item): string => $item['materia'].' / '.$item['submateria'])->unique()->values()->all();
        $unknownCities = $this->issueValues($items, 'CIUDAD_NO_ENCONTRADA');

        return [
            'filas_detectadas' => $collection->count(),
            'filas_validas' => $collection->whereIn('resultado', ['VÁLIDO', 'ADVERTENCIA'])->count(),
            'advertencias' => $collection->flatMap(fn (array $item): array => $item['issues'])->where('nivel', 'ADVERTENCIA')->count(),
            'errores' => $collection->where('resultado', 'ERROR')->count(),
            'posibles_duplicados' => $collection->where('resultado', 'POSIBLE DUPLICADO')->count(),
            'duplicados' => $collection->where('resultado', 'DUPLICADO')->count(),
            'causas_nuevas' => $collection->whereIn('resultado', ['VÁLIDO', 'ADVERTENCIA'])->count(),
            'materias_nuevas' => $newMatters,
            'submaterias_nuevas' => $newSubmatters,
            'ciudades_no_encontradas' => $unknownCities,
            'responsables_reconocidos' => $collection->filter(fn (array $item): bool => $item['data']['responsable_id'] !== null)->count(),
            'responsables_no_encontrados' => $this->issueValues($items, 'RESPONSABLE_NO_ENCONTRADO'),
            'actuaciones_detectadas' => $collection->sum('actuaciones_detectadas'),
            'ingresos_detectados' => $collection->where('ingreso', '>', 0)->count(),
            'egresos_detectados' => $collection->where('egreso', '>', 0)->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function issueValues(array $items, string $code): array
    {
        $values = [];

        foreach ($items as $item) {
            foreach ($item['issues'] as $issue) {
                if ($issue['codigo_error'] === $code && filled($issue['valor_original'])) {
                    $values[(string) $issue['valor_original']] = true;
                }
            }
        }

        return array_keys($values);
    }

    private function duplicateKey(?string $number, ?string $name, ?string $matter, ?string $court, ?string $city): string
    {
        return collect([$number, $name, $matter, $court, $city])->map(fn (?string $value): string => $this->headerMapper->normalizeComparable($value) ?? '')->implode('|');
    }

    private function possibleDuplicateKey(?string $number, ?string $name): string
    {
        return ($this->headerMapper->normalizeComparable($number) ?? '').'|'.($this->headerMapper->normalizeComparable($name) ?? '');
    }

    private function cleanText(mixed $value): ?string
    {
        return blank($value) ? null : Str::squish(trim((string) $value));
    }

    private function preservedText(mixed $value): ?string
    {
        return blank($value) ? null : trim((string) $value);
    }
}
