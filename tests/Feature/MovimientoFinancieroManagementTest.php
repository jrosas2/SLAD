<?php

use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\MovimientoFinanciero;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('el administrador puede registrar un ingreso', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-22')
        ->set('monto', '500000')
        ->set('observacion', 'Recuperación judicial')
        ->call('save')
        ->assertHasNoErrors();

    $movimiento = MovimientoFinanciero::query()->where('observacion', 'Recuperación judicial')->firstOrFail();
    expect($movimiento->tipo)->toBe(TipoMovimientoFinanciero::Ingreso)
        ->and($movimiento->monto)->toBe('500000.00');
});

test('el administrador puede registrar un egreso', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'EGRESO')
        ->set('fecha', '2026-08-21')
        ->set('monto', '75000')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->movimientosFinancieros()->firstOrFail()->tipo)->toBe(TipoMovimientoFinanciero::Egreso);
});

test('el abogado autorizado puede registrar movimientos', function () {
    $abogado = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $this->actingAs($abogado);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '120000')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->movimientosFinancieros()->count())->toBe(1);
});

test('el usuario consulta no puede registrar movimientos', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $this->actingAs($consulta);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '120000')
        ->call('save')
        ->assertForbidden();

    expect($causa->movimientosFinancieros()->count())->toBe(0);
});

test('el movimiento queda asociado a la causa visualizada', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $otraCausa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '80000')
        ->call('save');

    $movimiento = MovimientoFinanciero::query()->firstOrFail();
    expect($movimiento->causa->is($causa))->toBeTrue()
        ->and($movimiento->causa->is($otraCausa))->toBeFalse();
});

test('se registra automáticamente el usuario creador', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'EGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '30000')
        ->call('save');

    expect(MovimientoFinanciero::query()->firstOrFail()->creador->is($administrador))->toBeTrue();
});

test('no se permiten montos negativos', function () {
    $administrador = User::factory()->administrador()->create([]);
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => Causa::factory()->create()])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '-1')
        ->call('save')
        ->assertHasErrors(['monto' => 'gt']);
});

test('no se permite un monto cero', function () {
    $administrador = User::factory()->administrador()->create([]);
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => Causa::factory()->create()])
        ->set('tipo', 'EGRESO')
        ->set('fecha', '2026-08-20')
        ->set('monto', '0')
        ->call('save')
        ->assertHasErrors(['monto' => 'gt']);
});

test('no se permite un tipo de movimiento inválido', function () {
    $administrador = User::factory()->administrador()->create([]);
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => Causa::factory()->create()])
        ->set('tipo', 'AJUSTE')
        ->set('fecha', '2026-08-20')
        ->set('monto', '50000')
        ->call('save')
        ->assertHasErrors(['tipo']);
});

test('calcula correctamente ingresos egresos y saldo en CLP', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 1000000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 500000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 200000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 50000]);
    $this->actingAs($consulta);

    $resumen = Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->get('resumenFinanciero');

    expect($resumen)->toBe([
        'totalIngresos' => 1500000,
        'totalEgresos' => 250000,
        'saldo' => 1250000,
    ]);
});

test('el resumen ignora movimientos de otras causas', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $otraCausa = Causa::factory()->create();
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 100000]);
    MovimientoFinanciero::factory()->for($otraCausa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 900000]);
    $this->actingAs($consulta);

    $resumen = Livewire::test('causas.movimientos-financieros', ['causa' => $causa])->get('resumenFinanciero');

    expect($resumen['totalIngresos'])->toBe(100000);
});

test('editar un movimiento actualiza inmediatamente los totales', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'monto' => 500000,
        'created_by' => $administrador,
    ]);
    $this->actingAs($administrador);

    $component = Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('openEditModal', $movimiento->id)
        ->set('monto', '600000')
        ->call('save')
        ->assertHasNoErrors();

    expect($component->get('resumenFinanciero')['totalIngresos'])->toBe(600000)
        ->and($movimiento->refresh()->creador->is($administrador))->toBeTrue();
});

test('eliminar un movimiento actualiza los totales mediante borrado lógico', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 500000]);
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 150000]);
    $this->actingAs($administrador);

    $component = Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('requestDelete', $movimiento->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertHasNoErrors();

    expect($component->get('resumenFinanciero'))->toBe([
        'totalIngresos' => 500000,
        'totalEgresos' => 0,
        'saldo' => 500000,
    ])->and(MovimientoFinanciero::withTrashed()->findOrFail($movimiento->id)->trashed())->toBeTrue();
});

test('el usuario consulta no puede editar movimientos', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create(['monto' => 100000]);
    $this->actingAs($consulta);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('openEditModal', $movimiento->id)
        ->assertForbidden();

    expect($movimiento->refresh()->monto)->toBe('100000.00');
});

test('el usuario consulta no puede eliminar movimientos', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create();
    $this->actingAs($consulta);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('requestDelete', $movimiento->id)
        ->assertForbidden();

    expect($movimiento->fresh())->not->toBeNull();
});

test('el abogado no puede eliminar movimientos', function () {
    $abogado = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create();
    $this->actingAs($abogado);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('requestDelete', $movimiento->id)
        ->assertForbidden();
});

test('una causa puede mantener múltiples movimientos independientes', function () {
    $causa = Causa::factory()->create();
    MovimientoFinanciero::factory()->count(5)->for($causa)->create();

    expect($causa->movimientosFinancieros()->count())->toBe(5);
});

test('los movimientos se muestran en orden cronológico descendente', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    MovimientoFinanciero::factory()->for($causa)->create(['fecha' => '2026-01-10', 'observacion' => 'Movimiento antiguo']);
    MovimientoFinanciero::factory()->for($causa)->create(['fecha' => '2026-08-20', 'observacion' => 'Movimiento reciente']);
    MovimientoFinanciero::factory()->for($causa)->create(['fecha' => '2026-05-15', 'observacion' => 'Movimiento intermedio']);
    $this->actingAs($consulta);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->assertSeeInOrder(['Movimiento reciente', 'Movimiento intermedio', 'Movimiento antiguo']);
});

test('el administrador puede visualizar movimientos sin cargar la causa de forma diferida', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    MovimientoFinanciero::factory()->for($causa)->create(['observacion' => 'MOVIMIENTO VISIBLE']);

    Livewire::actingAs($administrador)
        ->test('causas.movimientos-financieros', ['causa' => $causa])
        ->assertSee('MOVIMIENTO VISIBLE');
});

test('no es posible manipular un movimiento para asociarlo a otra causa', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $otraCausa = Causa::factory()->create();
    $movimientoAjeno = MovimientoFinanciero::factory()->for($otraCausa)->create();
    $this->actingAs($administrador);

    expect(fn () => Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->call('openEditModal', $movimientoAjeno->id))
        ->toThrow(ModelNotFoundException::class);

    expect($movimientoAjeno->refresh()->causa->is($otraCausa))->toBeTrue();
});

test('el detalle de la causa muestra el resumen financiero en formato chileno', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Ingreso, 'monto' => 1500000]);
    MovimientoFinanciero::factory()->for($causa)->create(['tipo' => TipoMovimientoFinanciero::Egreso, 'monto' => 250000]);
    $this->actingAs($consulta);

    $this->get(route('causas.show', $causa))
        ->assertOk()
        ->assertSee('Movimientos financieros')
        ->assertSee('$1.500.000')
        ->assertSee('$250.000')
        ->assertSee('$1.250.000');
});

test('los movimientos no modifican el monto demandado de la causa', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create(['monto_demandado' => 10000000]);
    $this->actingAs($administrador);

    Livewire::test('causas.movimientos-financieros', ['causa' => $causa])
        ->set('tipo', 'INGRESO')
        ->set('fecha', '2026-08-22')
        ->set('monto', '3000000')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->monto_demandado)->toBe('10000000.00');
});
