<?php

use App\Enums\Permiso;
use App\Models\Causa;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function programacionSala(Causa $causa, string $fecha, string $sala = '1', array $atributos = []): PjudProgramacion
{
    return PjudProgramacion::query()->create([
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => 4901014,
        'tribunal_nombre' => 'CORTE DE APELACIONES DE TEMUCO',
        'tipo_tabla_codigo' => '3',
        'tipo_tabla' => 'ordinaria',
        'fecha_vista' => $fecha,
        'sala_numero' => $sala,
        'sala' => $sala === '1' ? 'PRIMERA' : 'SEGUNDA',
        'numero_orden' => 4,
        'numero_ingreso' => $causa->numero_causa,
        'tipo_coincidencia' => 'exacta',
        'caratulado' => 'PÉREZ CON FISCO',
        'huella' => hash('sha256', $causa->id.'|'.$fecha.'|'.$sala.'|'.uniqid('', true)),
        'detectada_en' => now(),
        ...$atributos,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00'));
});

test('el menú Monitoreo (Causas y Salas) exige el permiso monitoreo.ver', function () {
    $sinPermiso = User::factory()->create();
    $this->actingAs($sinPermiso)->get(route('monitoreo.salas'))->assertForbidden();
    $this->actingAs($sinPermiso)->get(route('monitoreo.causas'))->assertForbidden();

    $abogado = User::factory()->abogado()->create();
    $this->actingAs($abogado)->get(route('monitoreo.salas'))->assertOk()->assertSee('Monitoreo');
    $this->actingAs($abogado)->get(route('monitoreo.causas'))->assertOk();
});

test('el abogado sólo ve las salas de sus causas y el administrador ve todas', function () {
    $abogado = User::factory()->abogado()->create();
    $otro = User::factory()->abogado()->create();
    $propia = Causa::factory()->create(['responsable_id' => $abogado->id, 'numero_causa' => '1436-2026']);
    $ajena = Causa::factory()->create(['responsable_id' => $otro->id, 'numero_causa' => '2001-2026']);
    programacionSala($propia, '2026-11-12');
    programacionSala($ajena, '2026-11-12');

    $this->actingAs($abogado);
    Livewire::test('pages::monitoreo.salas')
        ->assertSee('1436-2026')
        ->assertDontSee('2001-2026');

    $this->actingAs(User::factory()->administrador()->create());
    Livewire::test('pages::monitoreo.salas')
        ->assertSee('1436-2026')
        ->assertSee('2001-2026');
});

test('el permiso monitoreo.ver-todas se puede asignar a cualquier rol', function () {
    $consulta = User::factory()->consulta()->create();
    $ajena = Causa::factory()->create(['responsable_id' => User::factory()->create()->id, 'numero_causa' => '2001-2026']);
    $vista = programacionSala($ajena, '2026-11-12');

    expect(PjudProgramacion::query()->visibleFor($consulta)->pluck('id')->all())->not->toContain($vista->id);

    $consulta->givePermissionTo(Permission::findOrCreate(Permiso::MonitoreoVerTodas->value, 'web'));

    expect(PjudProgramacion::query()->visibleFor($consulta->fresh())->pluck('id')->all())->toContain($vista->id);
});

test('marca como reprogramada la vista reemplazada por otra fecha o sala antes de realizarse', function () {
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026']);

    $this->travelTo(CarbonImmutable::parse('2026-11-05 15:00:00'));
    $original = programacionSala($causa, '2026-11-12', '1');

    $this->travelTo(CarbonImmutable::parse('2026-11-08 15:00:00'));
    $nueva = programacionSala($causa, '2026-11-19', '2');

    // Una vista posterior a la fecha ya realizada no es un cambio, es una vista nueva.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 15:00:00'));
    $otraVista = programacionSala($causa, '2026-12-10', '2');

    expect(PjudProgramacion::query()->reprogramadas()->pluck('id')->all())
        ->toContain($original->id)
        ->not->toContain($nueva->id)
        ->not->toContain($otraVista->id);
});

test('filtra por sala y muestra el historial con las modificaciones de la causa', function () {
    $this->actingAs(User::factory()->administrador()->create());
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026']);
    $otra = Causa::factory()->create(['numero_causa' => '2001-2026']);

    $this->travelTo(CarbonImmutable::parse('2026-11-05 15:00:00'));
    $original = programacionSala($causa, '2026-11-12', '1');
    $this->travelTo(CarbonImmutable::parse('2026-11-08 15:00:00'));
    programacionSala($causa, '2026-11-19', '2');
    programacionSala($otra, '2026-11-19', '1');

    Livewire::test('pages::monitoreo.salas')
        ->set('sala', '2')
        ->assertSee('1436-2026')
        ->assertDontSee('2001-2026')
        ->set('sala', '')
        ->set('estado', 'reprogramadas')
        ->assertSee('1436-2026')
        ->assertDontSee('2001-2026')
        ->call('verHistorial', $original->id)
        ->assertSet('showHistorial', true)
        ->assertSee('Fecha: 12/11/2026 → 19/11/2026')
        ->assertSee('Sala: PRIMERA → SEGUNDA');
});

test('un abogado no puede abrir el historial de una causa ajena', function () {
    $this->actingAs(User::factory()->abogado()->create());
    $ajena = programacionSala(Causa::factory()->create(['responsable_id' => User::factory()->create()->id]), '2026-11-12');

    Livewire::test('pages::monitoreo.salas')
        ->call('verHistorial', $ajena->id)
        ->assertNotFound();
});

test('agrupa las salas por día de vista', function () {
    $this->actingAs(User::factory()->administrador()->create());
    programacionSala(Causa::factory()->create(['numero_causa' => '1436-2026']), '2026-11-19');
    programacionSala(Causa::factory()->create(['numero_causa' => '982-2026']), '2026-11-19', '2');
    programacionSala(Causa::factory()->create(['numero_causa' => '200-2026']), '2026-11-17');

    Livewire::test('pages::monitoreo.salas')
        ->assertSeeInOrder(['Jueves 19 de noviembre de 2026', '2 causas', 'Martes 17 de noviembre de 2026', '1 causa']);
});

function movimientoEstadoDiario(Causa $causa, string $fecha, string $tramite): PjudMovimiento
{
    return PjudMovimiento::query()->create([
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => 4901014,
        'fecha_estado_diario' => $fecha,
        'numero_ingreso' => $causa->numero_causa,
        'tipo_coincidencia' => 'exacta',
        'tribunal_nombre' => 'CORTE DE APELACIONES DE TEMUCO',
        'caratulado' => 'PÉREZ CON FISCO',
        'tramite' => $tramite,
        'huella' => hash('sha256', $causa->id.'|'.$fecha.'|'.$tramite),
    ]);
}

test('monitoreo de causas agrupa los movimientos por día y respeta la visibilidad', function () {
    $abogado = User::factory()->abogado()->create();
    $propia = Causa::factory()->create(['responsable_id' => $abogado->id, 'numero_causa' => '1436-2026']);
    $ajena = Causa::factory()->create(['responsable_id' => User::factory()->create()->id, 'numero_causa' => '2001-2026']);
    movimientoEstadoDiario($propia, '2026-11-10', 'Autos en relación');
    movimientoEstadoDiario($propia, '2026-11-09', 'Se fija nueva fecha de vista');
    movimientoEstadoDiario($ajena, '2026-11-10', 'Trámite ajeno');

    $this->actingAs($abogado);
    Livewire::test('pages::monitoreo.causas')
        ->assertSeeInOrder(['Martes 10 de noviembre de 2026', 'Autos en relación', 'Lunes 9 de noviembre de 2026', 'Se fija nueva fecha de vista'])
        ->assertDontSee('Trámite ajeno');

    $this->actingAs(User::factory()->administrador()->create());
    Livewire::test('pages::monitoreo.causas')->assertSee('Trámite ajeno');
});
