<?php

use App\Enums\EstadoImportacion;
use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\Importacion;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\User;
use App\Services\Imports\HistoricalActuationParser;
use App\Services\Imports\HistoricalDateParser;
use App\Services\Imports\HistoricalExcelAnalyzer;
use App\Services\Imports\HistoricalHeaderMapper;
use App\Services\Imports\HistoricalImportService;
use App\Services\Imports\HistoricalMoneyParser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function historicalHeaders(): array
{
    return [
        'NOMBRE CASUSA', 'FECHA CAUSA', 'FECHA DE INGRESO', 'CIUDAD', 'NÚMERO DE JUZGADO',
        'MATERIA', 'SUB MATERIA', 'ESTADO PROCESAL', 'DIRECCION', 'A CARGO', 'NRO CAUSA',
        'ACCION', 'ESTADO', 'MONTO DEMANDADO', 'OBSERVACION CAUSA', 'OBSERVACION IMPORTANTE',
        'INGRESO', 'EGRESO', 'CON/SIN COTIZACIONES', 'FUNCIONARIO/A USUARIO/A',
    ];
}

function historicalRow(array $overrides = []): array
{
    $values = [
        'NOMBRE CASUSA' => 'Municipalidad con proveedor',
        'FECHA CAUSA' => '20.08.2026',
        'FECHA DE INGRESO' => '21/08/2026',
        'CIUDAD' => 'SANTIAGO',
        'NÚMERO DE JUZGADO' => '1° JUZGADO CIVIL',
        'MATERIA' => ' civil ',
        'SUB MATERIA' => 'Cobro pesos',
        'ESTADO PROCESAL' => null,
        'DIRECCION' => null,
        'A CARGO' => ' fv ',
        'NRO CAUSA' => 'C-4609',
        'ACCION' => null,
        'ESTADO' => null,
        'MONTO DEMANDADO' => '$1.500.000',
        'OBSERVACION CAUSA' => "11.12.25 LIQUIDACION COSTAS\n12/12/25 NO HA LUGAR",
        'OBSERVACION IMPORTANTE' => "Texto jurídico\ncon saltos",
        'INGRESO' => '500.000',
        'EGRESO' => '75.000',
        'CON/SIN COTIZACIONES' => 'CON',
        'FUNCIONARIO/A USUARIO/A' => 'Dato descartado',
    ];
    $values = [...$values, ...$overrides];

    return array_map(fn (string $header): mixed => $values[$header] ?? null, historicalHeaders());
}

function historicalSpreadsheet(array $rows, string $sheetName = 'B.D. GENERAL', ?array $headers = null): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetName);
    $sheet->fromArray($headers ?? historicalHeaders(), null, 'A1');

    foreach ($rows as $index => $row) {
        $sheet->fromArray($row, null, 'A'.($index + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'slad-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

function historicalUpload(string $path): UploadedFile
{
    return UploadedFile::fake()->createWithContent('historico.xlsx', (string) file_get_contents($path));
}

function prepareHistoricalCatalogs(): array
{
    $materia = Materia::factory()->create(['nombre' => 'CIVIL']);
    $ciudad = Ciudad::factory()->create(['nombre' => 'SANTIAGO']);
    $juzgado = Juzgado::factory()->for($ciudad)->create(['nombre' => '1° JUZGADO CIVIL']);
    $responsable = User::factory()->abogado()->create(['codigo' => 'FV']);

    return compact('materia', 'ciudad', 'juzgado', 'responsable');
}

test('solo usuarios con permiso pueden acceder al módulo de importación', function () {
    $administrador = User::factory()->administrador()->create();
    $abogado = User::factory()->abogado()->create();

    $this->actingAs($administrador)->get(route('admin.importaciones.index'))->assertOk()->assertSee('Importación histórica');
    $this->actingAs($abogado)->get(route('admin.importaciones.index'))->assertForbidden();
});

test('un archivo arbitrario es rechazado antes de crear trazabilidad', function () {
    Storage::fake('local');
    $administrador = User::factory()->administrador()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', UploadedFile::fake()->create('malicioso.txt', 10, 'text/plain'))
        ->call('analizar')
        ->assertHasErrors(['archivo']);

    expect(Importacion::query()->count())->toBe(0);
});

test('los encabezados históricos y el alias nombre casusa se reconocen centralmente', function () {
    $mapper = app(HistoricalHeaderMapper::class);
    $mapped = $mapper->map(historicalHeaders());

    expect($mapped)->toContain('nombre', 'numero_causa', 'responsable_codigo', 'observacion_causa')
        ->and($mapper->hasMinimumStructure($mapped))->toBeTrue();
});

test('el parser de fechas soporta formatos históricos y serial Excel sin inventar fallback', function () {
    $parser = app(HistoricalDateParser::class);

    expect($parser->parse('20.08.2026')?->toDateString())->toBe('2026-08-20')
        ->and($parser->parse('20/08/2026')?->toDateString())->toBe('2026-08-20')
        ->and($parser->parse('20-08-26')?->toDateString())->toBe('2026-08-20')
        ->and($parser->parse(45500))->not->toBeNull()
        ->and(fn () => $parser->parse('fecha imposible'))->toThrow(InvalidArgumentException::class);
});

test('el parser monetario interpreta CLP entero y rechaza decimales no enteros', function () {
    $parser = app(HistoricalMoneyParser::class);

    expect($parser->parse('$1.500.000'))->toBe(1500000)
        ->and($parser->parse('1500000'))->toBe(1500000)
        ->and($parser->parse('1.500.000,00'))->toBe(1500000)
        ->and(fn () => $parser->parse('1.500,50'))->toThrow(InvalidArgumentException::class);
});

test('las actuaciones se separan por fecha y el texto no separable nunca se pierde', function () {
    $parser = app(HistoricalActuationParser::class);
    $separated = $parser->parse('11.12.25: LIQUIDACION COSTAS 12/12/2025 NO HA LUGAR');
    $fallback = $parser->parse('Texto histórico sin una fecha reconocible');

    expect($separated['items'])->toHaveCount(2)
        ->and($separated['items'][0]['fecha'])->toBe('2025-12-11')
        ->and($fallback['items'])->toHaveCount(1)
        ->and($fallback['items'][0]['fecha'])->toBeNull()
        ->and($fallback['items'][0]['descripcion'])->toBe('Texto histórico sin una fecha reconocible');
});

test('el analizador detecta la hoja principal normaliza relaciones y no persiste causas', function () {
    $catalogos = prepareHistoricalCatalogs();
    $path = historicalSpreadsheet([historicalRow()]);
    $causasBefore = Causa::query()->count();

    $analysis = app(HistoricalExcelAnalyzer::class)->analyze($path);

    expect($analysis['sheet'])->toBe('B.D. GENERAL')
        ->and($analysis['summary']['filas_detectadas'])->toBe(1)
        ->and($analysis['items'][0]['data']['materia_id'])->toBe($catalogos['materia']->id)
        ->and($analysis['items'][0]['data']['juzgado_id'])->toBe($catalogos['juzgado']->id)
        ->and($analysis['items'][0]['data']['responsable_id'])->toBe($catalogos['responsable']->id)
        ->and(Causa::query()->count())->toBe($causasBefore);
});

test('una fecha inválida queda como error conservando su valor original', function () {
    prepareHistoricalCatalogs();
    $path = historicalSpreadsheet([historicalRow(['FECHA CAUSA' => '99/99/2026'])]);

    $analysis = app(HistoricalExcelAnalyzer::class)->analyze($path);

    expect($analysis['items'][0]['resultado'])->toBe('ERROR')
        ->and(collect($analysis['issues'])->firstWhere('codigo_error', 'FECHA_INVALIDA')['valor_original'])->toBe('99/99/2026');
});

test('un juzgado no encontrado genera advertencia y queda sin relación', function () {
    $catalogos = prepareHistoricalCatalogs();
    $path = historicalSpreadsheet([historicalRow(['NÚMERO DE JUZGADO' => 'Juzgado inexistente'])]);

    $analysis = app(HistoricalExcelAnalyzer::class)->analyze($path);

    expect($analysis['items'][0]['data']['juzgado_id'])->toBeNull()
        ->and(collect($analysis['issues'])->contains('codigo_error', 'JUZGADO_AMBIGUO'))->toBeTrue();
});

test('la confirmación crea causa submateria actuaciones y movimientos sin fechas ficticias', function () {
    Storage::fake('local');
    $catalogos = prepareHistoricalCatalogs();
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow()]);
    $this->actingAs($administrador);

    $component = Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))
        ->call('analizar')
        ->assertHasNoErrors();

    expect(Causa::query()->count())->toBe(0);

    $component->set('confirmacion', true)->call('confirmarImportacion')->assertHasNoErrors();
    $causa = Causa::query()->with(['actuaciones', 'movimientosFinancieros', 'submateria'])->firstOrFail();

    expect($causa->numero_causa)->toBe('C-4609')
        ->and($causa->responsable_id)->toBe($catalogos['responsable']->id)
        ->and($causa->submateria?->materia_id)->toBe($catalogos['materia']->id)
        ->and($causa->actuaciones)->toHaveCount(2)
        ->and($causa->actuaciones->every(fn ($actuacion): bool => $actuacion->estado_procesal_id === null && $actuacion->created_by === null))->toBeTrue()
        ->and($causa->movimientosFinancieros)->toHaveCount(2)
        ->and($causa->movimientosFinancieros->every(fn ($movimiento): bool => $movimiento->fecha === null && $movimiento->created_by === null))->toBeTrue()
        ->and($causa->movimientosFinancieros->pluck('tipo')->all())->toContain(TipoMovimientoFinanciero::Ingreso, TipoMovimientoFinanciero::Egreso)
        ->and(Schema::hasColumn('causas', 'funcionario_usuario'))->toBeFalse()
        ->and(Importacion::query()->latest()->firstOrFail()->user_id)->toBe($administrador->id)
        ->and(Importacion::query()->latest()->firstOrFail()->estado)->toBe(EstadoImportacion::Completada)
        ->and(Storage::disk('local')->allFiles('importaciones'))->toBeEmpty();
});

test('un responsable desconocido no crea usuario y la causa queda sin responsable', function () {
    Storage::fake('local');
    prepareHistoricalCatalogs();
    $administrador = User::factory()->administrador()->create();
    $usersBefore = User::query()->count();
    $path = historicalSpreadsheet([historicalRow(['A CARGO' => 'XX'])]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))
        ->call('analizar')
        ->set('confirmacion', true)
        ->call('confirmarImportacion')
        ->assertHasNoErrors();

    expect(Causa::query()->firstOrFail()->responsable_id)->toBeNull()
        ->and(User::query()->count())->toBe($usersBefore);
});

test('los duplicados se detectan por combinación y no actualizan ni duplican causas', function () {
    Storage::fake('local');
    $catalogos = prepareHistoricalCatalogs();
    Causa::factory()->create([
        'nombre' => 'Municipalidad con proveedor',
        'numero_causa' => 'C-4609',
        'materia_id' => $catalogos['materia'],
        'juzgado_id' => $catalogos['juzgado'],
    ]);
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow()]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))
        ->call('analizar')
        ->assertSet('previsualizacion.0.resultado', 'DUPLICADO')
        ->set('confirmacion', true)
        ->call('confirmarImportacion')
        ->assertHasNoErrors();

    expect(Causa::query()->count())->toBe(1);
});

test('el hash de un archivo ya completado bloquea una segunda ejecución', function () {
    Storage::fake('local');
    prepareHistoricalCatalogs();
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow()]);
    $contents = file_get_contents($path);
    $firstPath = tempnam(sys_get_temp_dir(), 'slad-first-').'.xlsx';
    $secondPath = tempnam(sys_get_temp_dir(), 'slad-second-').'.xlsx';
    file_put_contents($firstPath, $contents);
    file_put_contents($secondPath, $contents);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($firstPath))->call('analizar')
        ->set('confirmacion', true)->call('confirmarImportacion')->assertHasNoErrors();

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($secondPath))->call('analizar')
        ->assertSet('hashDuplicado', true)
        ->set('confirmacion', true)->call('confirmarImportacion')
        ->assertHasErrors(['confirmacion']);

    expect(Causa::query()->count())->toBe(1);
});

test('el servicio de importación también protege el backend por permiso', function () {
    Storage::fake('local');
    $abogado = User::factory()->abogado()->create();
    $importacion = Importacion::factory()->for($abogado, 'usuario')->create();

    expect(fn () => app(HistoricalImportService::class)->import($importacion, $abogado))
        ->toThrow(AuthorizationException::class);
});

test('una fila con error se omite sin crear catálogos parciales', function () {
    Storage::fake('local');
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow(['MATERIA' => 'MATERIA NUEVA', 'FECHA CAUSA' => 'inválida'])]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))->call('analizar')
        ->set('confirmacion', true)->call('confirmarImportacion')->assertHasNoErrors();

    expect(Causa::query()->count())->toBe(0)
        ->and(Materia::query()->where('nombre', 'MATERIA NUEVA')->exists())->toBeFalse()
        ->and(Importacion::query()->latest()->firstOrFail()->filas_error)->toBe(1);
});

test('estados ciudades y acciones desconocidos se advierten pero no se inventan', function () {
    prepareHistoricalCatalogs();
    $path = historicalSpreadsheet([historicalRow([
        'CIUDAD' => 'CIUDAD INEXISTENTE',
        'ESTADO PROCESAL' => 'ESTADO INVENTADO',
        'ACCION' => 'ACCION INVENTADA',
    ])]);

    $analysis = app(HistoricalExcelAnalyzer::class)->analyze($path);
    $codes = collect($analysis['issues'])->pluck('codigo_error');

    expect($analysis['items'][0]['resultado'])->toBe('ADVERTENCIA')
        ->and($analysis['items'][0]['data']['juzgado_id'])->toBeNull()
        ->and($codes)->toContain('CIUDAD_NO_ENCONTRADA', 'ESTADO_PROCESAL_NO_ENCONTRADO', 'ACCION_NO_ENCONTRADO');
});

test('una coincidencia parcial se clasifica posible duplicado y se omite', function () {
    Storage::fake('local');
    $catalogos = prepareHistoricalCatalogs();
    Causa::factory()->create([
        'nombre' => 'Municipalidad con proveedor',
        'numero_causa' => 'C-4609',
        'materia_id' => $catalogos['materia'],
        'juzgado_id' => $catalogos['juzgado'],
    ]);
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow(['MATERIA' => 'LABORAL'])]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))->call('analizar')
        ->assertSet('previsualizacion.0.resultado', 'POSIBLE DUPLICADO')
        ->set('confirmacion', true)->call('confirmarImportacion')->assertHasNoErrors();

    expect(Causa::query()->count())->toBe(1)
        ->and(Materia::query()->where('nombre', 'LABORAL')->exists())->toBeFalse();
});

test('los errores de análisis quedan asociados estructuradamente al usuario ejecutor', function () {
    Storage::fake('local');
    prepareHistoricalCatalogs();
    $administrador = User::factory()->administrador()->create();
    $path = historicalSpreadsheet([historicalRow(['FECHA CAUSA' => 'fecha inválida'])]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.importaciones.index')
        ->set('archivo', historicalUpload($path))->call('analizar')->assertHasNoErrors();

    $importacion = Importacion::query()->with('errores')->latest()->firstOrFail();

    expect($importacion->user_id)->toBe($administrador->id)
        ->and($importacion->estado)->toBe(EstadoImportacion::Analizada)
        ->and($importacion->errores)->not->toBeEmpty()
        ->and($importacion->errores->contains('codigo_error', 'FECHA_INVALIDA'))->toBeTrue()
        ->and($importacion->errores->firstWhere('codigo_error', 'FECHA_INVALIDA')?->datos_originales)->toBeArray();
});
