<?php

use App\Enums\TipoMovimientoFinanciero;
use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\MovimientoFinanciero;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function dashboardFilters(array $overrides = []): array
{
    return array_replace([
        'year' => null,
        'materiaId' => null,
        'responsableId' => null,
        'estadoCausaId' => null,
        'estadoProcesalId' => null,
        'ciudadId' => null,
        'juzgadoId' => null,
    ], $overrides);
}

test('el administrador puede visualizar el dashboard estadístico', function () {
    $administrador = User::factory()->administrador()->create([]);

    $this->actingAs($administrador)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Panel administrativo y jurídico')
        ->assertSee('Total de causas')
        ->assertSeeHtml('data-testid="circular-chart-estados"')
        ->assertSeeHtml('data-testid="circular-chart-estados-procesales"');
});

test('el abogado autorizado puede visualizar estadísticas institucionales', function () {
    $abogado = User::factory()->abogado()->create([]);
    Causa::factory()->create(['fecha_ingreso' => now()->startOfYear()]);
    $this->actingAs($abogado);

    Livewire::test('pages::dashboard')
        ->assertSee('Panel administrativo y jurídico')
        ->assertSee('Total de causas');
});

test('el usuario consulta visualiza el dashboard de solo lectura', function () {
    $consulta = User::factory()->consulta()->create([]);
    Causa::factory()->create(['fecha_ingreso' => now()->startOfYear()]);

    $this->actingAs($consulta)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Panel administrativo y jurídico')
        ->assertSee('Monto total demandado');

    Livewire::test('pages::dashboard')->assertOk();
});

test('calcula el total de causas', function () {
    Causa::factory()->count(4)->create();

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters());

    expect($summary['totalCausas'])->toBe(4);
});

test('calcula causas vigentes y cerradas desde el catálogo', function () {
    $vigente = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    $cerrada = EstadoCausa::factory()->create(['nombre' => 'CERRADA']);
    Causa::factory()->count(7)->create(['estado_causa_id' => $vigente]);
    Causa::factory()->count(3)->create(['estado_causa_id' => $cerrada]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters());

    expect($summary['causasVigentes'])->toBe(7)
        ->and($summary['causasCerradas'])->toBe(3);
});

test('calcula el monto total demandado en CLP sin confundirlo con ingresos', function () {
    $causa = Causa::factory()->create(['monto_demandado' => 100000]);
    Causa::factory()->create(['monto_demandado' => 200000]);
    Causa::factory()->create(['monto_demandado' => 300000]);
    MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'monto' => 1500000,
    ]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters());

    expect($summary['montoDemandado'])->toBe(600000)
        ->and($summary['totalIngresos'])->toBe(1500000);
});

test('calcula ingresos egresos y saldo financiero', function () {
    $causa = Causa::factory()->create();
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 1000000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 500000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 300000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 50000]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters());

    expect($summary['totalIngresos'])->toBe(1500000)
        ->and($summary['totalEgresos'])->toBe(350000)
        ->and($summary['saldo'])->toBe(1150000);
});

test('agrupa las causas por materia ordenadas por cantidad', function () {
    $civil = Materia::factory()->create(['nombre' => 'CIVIL']);
    $laboral = Materia::factory()->create(['nombre' => 'LABORAL']);
    Causa::factory()->count(3)->for($civil)->create();
    Causa::factory()->count(2)->for($laboral)->create();

    $grouped = app(DashboardStatsService::class)->causasPorMateria(dashboardFilters());

    expect($grouped)->toMatchArray([
        ['id' => $civil->id, 'nombre' => 'CIVIL', 'cantidad' => 3],
        ['id' => $laboral->id, 'nombre' => 'LABORAL', 'cantidad' => 2],
    ]);
});

test('agrupa por responsable e incluye la categoría sin responsable', function () {
    $responsable = User::factory()->create(['codigo' => 'FV', 'name' => 'Francisco Vergara']);
    Causa::factory()->count(2)->create(['responsable_id' => $responsable]);
    Causa::factory()->create(['responsable_id' => null]);

    $grouped = collect(app(DashboardStatsService::class)->causasPorResponsable(dashboardFilters()));

    expect($grouped->firstWhere('id', $responsable->id)['cantidad'])->toBe(2)
        ->and($grouped->firstWhere('id', null)['nombre'])->toBe('SIN RESPONSABLE');
});

test('agrupa las causas por estado procesal', function () {
    $probatorio = EstadoProcesal::factory()->create(['nombre' => 'PROBATORIO']);
    Causa::factory()->count(2)->create(['estado_procesal_id' => $probatorio]);
    Causa::factory()->create(['estado_procesal_id' => null]);

    $grouped = collect(app(DashboardStatsService::class)->causasPorEstadoProcesal(dashboardFilters()));

    expect($grouped->firstWhere('id', $probatorio->id)['cantidad'])->toBe(2)
        ->and($grouped->firstWhere('id', null)['cantidad'])->toBe(1);
});

test('el filtro anual de causas usa exclusivamente fecha de ingreso', function () {
    Causa::factory()->create(['fecha_ingreso' => '2026-03-01', 'fecha_causa' => '2025-01-01']);
    Causa::factory()->create(['fecha_ingreso' => '2025-03-01', 'fecha_causa' => '2026-01-01']);
    Causa::factory()->create(['fecha_ingreso' => null, 'fecha_causa' => '2026-06-01']);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters(['year' => 2026]));

    expect($summary['totalCausas'])->toBe(1);
});

test('el filtro anual financiero utiliza la fecha efectiva del movimiento', function () {
    $causa = Causa::factory()->create(['fecha_ingreso' => '2025-01-01']);
    MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'fecha' => '2026-04-01',
        'monto' => 450000,
    ]);
    MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'fecha' => '2025-04-01',
        'monto' => 900000,
    ]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters(['year' => 2026]));

    expect($summary['totalIngresos'])->toBe(450000);
});

test('el filtro por materia actualiza todas las estadísticas compatibles', function () {
    $civil = Materia::factory()->create();
    $laboral = Materia::factory()->create();
    $causaCivil = Causa::factory()->for($civil)->create();
    $causaLaboral = Causa::factory()->for($laboral)->create();
    MovimientoFinanciero::factory()->for($causaCivil)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 100000]);
    MovimientoFinanciero::factory()->for($causaLaboral)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 900000]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters(['materiaId' => $civil->id]));

    expect($summary['totalCausas'])->toBe(1)
        ->and($summary['totalIngresos'])->toBe(100000);
});

test('el filtro por responsable limita las causas', function () {
    $responsable = User::factory()->create();
    Causa::factory()->count(2)->create(['responsable_id' => $responsable]);
    Causa::factory()->create(['responsable_id' => null]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters(['responsableId' => $responsable->id]));

    expect($summary['totalCausas'])->toBe(2);
});

test('el filtro por estado de causa limita los indicadores', function () {
    $vigente = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    $cerrada = EstadoCausa::factory()->create(['nombre' => 'CERRADA']);
    Causa::factory()->count(2)->create(['estado_causa_id' => $vigente]);
    Causa::factory()->create(['estado_causa_id' => $cerrada]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters(['estadoCausaId' => $cerrada->id]));

    expect($summary['totalCausas'])->toBe(1)
        ->and($summary['causasCerradas'])->toBe(1);
});

test('combina año materia responsable y estado', function () {
    $materia = Materia::factory()->create();
    $otraMateria = Materia::factory()->create();
    $responsable = User::factory()->create();
    $estado = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    Causa::factory()->create([
        'fecha_ingreso' => '2026-02-01',
        'materia_id' => $materia,
        'responsable_id' => $responsable,
        'estado_causa_id' => $estado,
    ]);
    Causa::factory()->create(['fecha_ingreso' => '2025-02-01', 'materia_id' => $materia, 'responsable_id' => $responsable, 'estado_causa_id' => $estado]);
    Causa::factory()->create(['fecha_ingreso' => '2026-02-01', 'materia_id' => $otraMateria, 'responsable_id' => $responsable, 'estado_causa_id' => $estado]);

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters([
        'year' => 2026,
        'materiaId' => $materia->id,
        'responsableId' => $responsable->id,
        'estadoCausaId' => $estado->id,
    ]));

    expect($summary['totalCausas'])->toBe(1);
});

test('los filtros por ciudad y juzgado respetan la relación de la causa', function () {
    $santiago = Ciudad::factory()->create();
    $valparaiso = Ciudad::factory()->create();
    $juzgadoSantiago = Juzgado::factory()->for($santiago)->create();
    $juzgadoValparaiso = Juzgado::factory()->for($valparaiso)->create();
    Causa::factory()->create(['juzgado_id' => $juzgadoSantiago]);
    Causa::factory()->create(['juzgado_id' => $juzgadoValparaiso]);

    $service = app(DashboardStatsService::class);

    expect($service->resumen(dashboardFilters(['ciudadId' => $santiago->id]))['totalCausas'])->toBe(1)
        ->and($service->resumen(dashboardFilters(['juzgadoId' => $juzgadoValparaiso->id]))['totalCausas'])->toBe(1);
});

test('los meses sin causas conservan un valor cero', function () {
    Causa::factory()->create(['fecha_ingreso' => '2026-03-10']);

    $months = app(DashboardStatsService::class)->causasPorMes(dashboardFilters(['year' => 2026]));

    expect($months)->toHaveCount(12)
        ->and($months[0]['nombre'])->toBe('ENE')
        ->and($months[0]['cantidad'])->toBe(0)
        ->and($months[2]['cantidad'])->toBe(1)
        ->and($months[11]['nombre'])->toBe('DIC');
});

test('calcula el aumento porcentual de causas respecto al año anterior', function () {
    Causa::factory()->count(4)->create(['fecha_ingreso' => '2025-06-10']);
    Causa::factory()->count(6)->create(['fecha_ingreso' => '2026-06-10']);

    $variation = app(DashboardStatsService::class)->variacionCausasAnual(dashboardFilters(['year' => 2026]));

    expect($variation)->toMatchArray([
        'anioActual' => 2026,
        'anioAnterior' => 2025,
        'totalActual' => 6,
        'totalAnterior' => 4,
        'porcentaje' => 50.0,
    ]);
});

test('calcula la disminución porcentual de causas respecto al año anterior', function () {
    Causa::factory()->count(8)->create(['fecha_ingreso' => '2025-06-10']);
    Causa::factory()->count(2)->create(['fecha_ingreso' => '2026-06-10']);

    $variation = app(DashboardStatsService::class)->variacionCausasAnual(dashboardFilters(['year' => 2026]));

    expect($variation['porcentaje'])->toBe(-75.0);
});

test('evita dividir por cero cuando el año anterior no tiene causas', function () {
    Causa::factory()->count(3)->create(['fecha_ingreso' => '2026-06-10']);

    $variation = app(DashboardStatsService::class)->variacionCausasAnual(dashboardFilters(['year' => 2026]));

    expect($variation['totalActual'])->toBe(3)
        ->and($variation['totalAnterior'])->toBe(0)
        ->and($variation['porcentaje'])->toBeNull();
});

test('muestra la variación anual dentro del cuadro de ingreso mensual', function () {
    $administrador = User::factory()->administrador()->create();
    Causa::factory()->count(2)->create(['fecha_ingreso' => '2025-04-10']);
    Causa::factory()->count(3)->create(['fecha_ingreso' => '2026-04-10']);
    $this->actingAs($administrador);

    Livewire::test('pages::dashboard')
        ->set('year', '2026')
        ->assertSeeHtml('data-testid="annual-cause-variation"')
        ->assertSee('Variación respecto al año anterior')
        ->assertSee('+50,0%')
        ->assertSee('3 causas en 2026 frente a 2 en 2025.');
});

test('los meses financieros vacíos conservan ingresos y egresos en cero', function () {
    $causa = Causa::factory()->create();
    MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Egreso,
        'fecha' => '2026-07-15',
        'monto' => 75000,
    ]);

    $months = app(DashboardStatsService::class)->finanzasPorMes(dashboardFilters(['year' => 2026]));

    expect($months)->toHaveCount(12)
        ->and($months[0]['ingresos'])->toBe(0)
        ->and($months[0]['egresos'])->toBe(0)
        ->and($months[6]['egresos'])->toBe(75000);
});

test('el dashboard funciona con la base de datos vacía', function () {
    $data = app(DashboardStatsService::class)->dashboard(dashboardFilters(['year' => 2026]));

    expect($data['resumen'])->toMatchArray([
        'totalCausas' => 0,
        'montoDemandado' => 0,
        'totalIngresos' => 0,
        'totalEgresos' => 0,
        'saldo' => 0,
    ])->and($data['causasPorMes'])->toHaveCount(12)
        ->and($data['actividadReciente'])->toBeEmpty();
});

test('la actividad reciente respeta fecha orden id y límite', function () {
    $causa = Causa::factory()->create();
    $usuario = User::factory()->create();
    Actuacion::factory()->count(8)->for($causa)->create(['fecha' => '2026-01-01', 'created_by' => $usuario]);
    $ultima = Actuacion::factory()->for($causa)->create(['fecha' => '2026-08-22', 'descripcion' => 'Última actuación', 'created_by' => $usuario]);

    $activity = app(DashboardStatsService::class)->actividadReciente(dashboardFilters(['year' => 2026]));

    expect($activity)->toHaveCount(8)
        ->and($activity->first()->is($ultima))->toBeTrue()
        ->and($activity->first()->relationLoaded('causa'))->toBeTrue()
        ->and($activity->first()->relationLoaded('creador'))->toBeTrue();
});

test('el filtro anual de actividad usa la fecha de la actuación', function () {
    $causa = Causa::factory()->create(['fecha_ingreso' => '2025-01-01']);
    Actuacion::factory()->for($causa)->create(['fecha' => '2026-05-01', 'descripcion' => 'Actuación 2026']);
    Actuacion::factory()->for($causa)->create(['fecha' => '2025-05-01', 'descripcion' => 'Actuación 2025']);

    $activity = app(DashboardStatsService::class)->actividadReciente(dashboardFilters(['year' => 2026]));

    expect($activity->pluck('descripcion')->all())->toBe(['Actuación 2026']);
});

test('los borrados lógicos no afectan las estadísticas', function () {
    $causaVigente = Causa::factory()->create(['monto_demandado' => 100000]);
    $causaEliminada = Causa::factory()->create(['monto_demandado' => 900000]);
    $causaEliminada->delete();
    $movimientoEliminado = MovimientoFinanciero::factory()->for($causaVigente)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'monto' => 500000,
    ]);
    $movimientoEliminado->delete();

    $summary = app(DashboardStatsService::class)->resumen(dashboardFilters());

    expect($summary['totalCausas'])->toBe(1)
        ->and($summary['montoDemandado'])->toBe(100000)
        ->and($summary['totalIngresos'])->toBe(0);
});

test('el componente Livewire actualiza los indicadores al combinar filtros', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materia = Materia::factory()->create();
    $responsable = User::factory()->create();
    Causa::factory()->create(['fecha_ingreso' => '2026-03-01', 'materia_id' => $materia, 'responsable_id' => $responsable]);
    Causa::factory()->create(['fecha_ingreso' => '2026-03-01']);
    $this->actingAs($administrador);

    $component = Livewire::test('pages::dashboard')
        ->set('year', '2026')
        ->set('materiaFilter', (string) $materia->id)
        ->set('responsableFilter', (string) $responsable->id);

    expect($component->get('dashboardData')['resumen']['totalCausas'])->toBe(1);
});

test('la carga principal evita N más uno y mantiene consultas acotadas', function () {
    $materia = Materia::factory()->create();
    $responsable = User::factory()->create();
    $causa = Causa::factory()->create(['materia_id' => $materia, 'responsable_id' => $responsable]);
    Actuacion::factory()->count(3)->for($causa)->create();
    MovimientoFinanciero::factory()->count(3)->for($causa)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DashboardStatsService::class)->dashboard(dashboardFilters());
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(22);
});
