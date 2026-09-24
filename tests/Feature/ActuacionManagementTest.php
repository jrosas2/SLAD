<?php

use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\EstadoProcesal;
use App\Models\User;
use Livewire\Livewire;

test('el administrador puede registrar una actuación', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('openCreateModal')
        ->set('fecha', '2026-08-22')
        ->set('descripcion', 'Se recibe la causa a prueba.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Actuacion::query()->where('descripcion', 'Se recibe la causa a prueba.')->exists())->toBeTrue();
});

test('el abogado autorizado puede registrar una actuación', function () {
    $abogado = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $this->actingAs($abogado);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2026-08-20')
        ->set('descripcion', 'Actuación registrada por abogado.')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->actuaciones()->where('descripcion', 'Actuación registrada por abogado.')->exists())->toBeTrue();
});

test('el usuario consulta no puede registrar actuaciones', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $this->actingAs($consulta);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2026-08-20')
        ->set('descripcion', 'Registro no autorizado.')
        ->call('save')
        ->assertForbidden();

    expect(Actuacion::query()->where('descripcion', 'Registro no autorizado.')->exists())->toBeFalse();
});

test('la actuación queda relacionada con la causa visualizada', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $otraCausa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2026-08-18')
        ->set('descripcion', 'Actuación de la causa correcta.')
        ->call('save');

    $actuacion = Actuacion::query()->where('descripcion', 'Actuación de la causa correcta.')->firstOrFail();

    expect($actuacion->causa->is($causa))->toBeTrue()
        ->and($actuacion->causa->is($otraCausa))->toBeFalse();
});

test('se registra automáticamente el usuario creador', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2026-08-18')
        ->set('descripcion', 'Actuación con autor.')
        ->call('save');

    expect(Actuacion::query()->where('descripcion', 'Actuación con autor.')->firstOrFail()->creador->is($administrador))->toBeTrue();
});

test('se guardan la fecha procesal y el estado procesal', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $estado = EstadoProcesal::factory()->create(['nombre' => 'PROBATORIO']);
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2025-12-15')
        ->set('estadoProcesalId', (string) $estado->id)
        ->set('descripcion', 'Se abre término probatorio.')
        ->call('save')
        ->assertHasNoErrors();

    $actuacion = Actuacion::query()->where('descripcion', 'Se abre término probatorio.')->firstOrFail();

    expect($actuacion->fecha->format('Y-m-d'))->toBe('2025-12-15')
        ->and($actuacion->estadoProcesal->is($estado))->toBeTrue();
});

test('la fecha y la descripción son obligatorias', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '')
        ->set('descripcion', '')
        ->call('save')
        ->assertHasErrors([
            'fecha' => 'required',
            'descripcion' => 'required',
        ]);
});

test('el historial se ordena por fecha e id de forma descendente', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    Actuacion::factory()->for($causa)->create(['fecha' => '2025-12-11', 'descripcion' => 'Primera actuación']);
    Actuacion::factory()->for($causa)->create(['fecha' => '2025-12-15', 'descripcion' => 'Segunda actuación']);
    Actuacion::factory()->for($causa)->create(['fecha' => '2025-12-15', 'descripcion' => 'Tercera actuación']);
    $this->actingAs($consulta);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->assertSeeInOrder([
            'Tercera actuación',
            'Segunda actuación',
            'Primera actuación',
        ]);
});

test('el administrador puede visualizar actuaciones sin cargar la causa de forma diferida', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    Actuacion::factory()->for($causa)->create(['descripcion' => 'ACTUACIÓN VISIBLE']);

    Livewire::actingAs($administrador)
        ->test('causas.actuaciones', ['causa' => $causa])
        ->assertSee('ACTUACIÓN VISIBLE');
});

test('el administrador puede editar una actuación sin cambiar su creador', function () {
    $administrador = User::factory()->administrador()->create([]);
    $creadorOriginal = User::factory()->create();
    $causa = Causa::factory()->create();
    $actuacion = Actuacion::factory()->for($causa)->create([
        'created_by' => $creadorOriginal,
        'descripcion' => 'Texto original',
    ]);
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('openEditModal', $actuacion->id)
        ->set('fecha', '2026-08-21')
        ->set('descripcion', "Texto actualizado\ncon segunda línea.")
        ->call('save')
        ->assertHasNoErrors();

    expect($actuacion->refresh()->descripcion)->toBe("Texto actualizado\ncon segunda línea.")
        ->and($actuacion->creador->is($creadorOriginal))->toBeTrue();
});

test('el administrador puede eliminar una actuación mediante borrado lógico', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $actuacion = Actuacion::factory()->for($causa)->create();
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('requestDelete', $actuacion->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Actuacion::query()->find($actuacion->id))->toBeNull()
        ->and(Actuacion::withTrashed()->findOrFail($actuacion->id)->trashed())->toBeTrue();
});

test('el usuario consulta no puede editar actuaciones', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $actuacion = Actuacion::factory()->for($causa)->create(['descripcion' => 'Texto protegido']);
    $this->actingAs($consulta);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('openEditModal', $actuacion->id)
        ->assertForbidden();

    expect($actuacion->refresh()->descripcion)->toBe('Texto protegido');
});

test('el usuario consulta no puede eliminar actuaciones', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $actuacion = Actuacion::factory()->for($causa)->create();
    $this->actingAs($consulta);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('requestDelete', $actuacion->id)
        ->assertForbidden();

    expect(Actuacion::query()->whereKey($actuacion)->exists())->toBeTrue();
});

test('una causa puede mantener múltiples actuaciones independientes', function () {
    $causa = Causa::factory()->create();

    Actuacion::factory()->count(4)->for($causa)->create();

    expect($causa->actuaciones()->count())->toBe(4);
});

test('las actuaciones se visualizan dentro del detalle de la causa', function () {
    $consulta = User::factory()->consulta()->create(['name' => 'Usuario Consulta']);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2025-12-15',
        'descripcion' => "Se dicta sentencia.\nNotifíquese por cédula.",
        'created_by' => $consulta,
    ]);
    $this->actingAs($consulta);

    $this->get(route('causas.show', $causa))
        ->assertOk()
        ->assertSee('Actuaciones')
        ->assertSee('Se dicta sentencia.')
        ->assertSee('Notifíquese por cédula.')
        ->assertSee('Usuario Consulta');
});

test('la causa adopta el estado de la actuación vigente más reciente', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create(['estado_procesal_id' => null]);
    $probatorio = EstadoProcesal::factory()->create(['nombre' => 'PROBATORIO']);
    $sentencia = EstadoProcesal::factory()->create(['nombre' => 'SENTENCIA']);
    $this->actingAs($administrador);

    $component = Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->set('fecha', '2026-08-20')
        ->set('estadoProcesalId', (string) $sentencia->id)
        ->set('descripcion', 'Se dicta sentencia.')
        ->call('save')
        ->assertHasNoErrors();

    $component
        ->set('fecha', '2026-01-10')
        ->set('estadoProcesalId', (string) $probatorio->id)
        ->set('descripcion', 'Actuación histórica incorporada después.')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->estadoProcesal->is($sentencia))->toBeTrue();
});

test('editar la fecha de una actuación recalcula el estado vigente', function () {
    $administrador = User::factory()->administrador()->create([]);
    $probatorio = EstadoProcesal::factory()->create(['nombre' => 'PROBATORIO']);
    $sentencia = EstadoProcesal::factory()->create(['nombre' => 'SENTENCIA']);
    $causa = Causa::factory()->create(['estado_procesal_id' => $sentencia]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2026-01-10',
        'estado_procesal_id' => $probatorio,
    ]);
    $actuacionVigente = Actuacion::factory()->for($causa)->create([
        'fecha' => '2026-08-20',
        'estado_procesal_id' => $sentencia,
    ]);
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('openEditModal', $actuacionVigente->id)
        ->set('fecha', '2025-12-01')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->estadoProcesal->is($probatorio))->toBeTrue();
});

test('eliminar la actuación vigente restaura el estado de la anterior', function () {
    $administrador = User::factory()->administrador()->create([]);
    $probatorio = EstadoProcesal::factory()->create(['nombre' => 'PROBATORIO']);
    $sentencia = EstadoProcesal::factory()->create(['nombre' => 'SENTENCIA']);
    $causa = Causa::factory()->create(['estado_procesal_id' => $sentencia]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2026-01-10',
        'estado_procesal_id' => $probatorio,
    ]);
    $actuacionVigente = Actuacion::factory()->for($causa)->create([
        'fecha' => '2026-08-20',
        'estado_procesal_id' => $sentencia,
    ]);
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('requestDelete', $actuacionVigente->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect($causa->refresh()->estadoProcesal->is($probatorio))->toBeTrue();
});

test('un estado procesal inactivo permanece visible al editar una actuación histórica', function () {
    $administrador = User::factory()->administrador()->create([]);
    $estadoInactivo = EstadoProcesal::factory()->create(['nombre' => 'ESTADO HISTÓRICO', 'activo' => false]);
    $causa = Causa::factory()->create();
    $actuacion = Actuacion::factory()->for($causa)->create(['estado_procesal_id' => $estadoInactivo]);
    $this->actingAs($administrador);

    Livewire::test('causas.actuaciones', ['causa' => $causa])
        ->call('openEditModal', $actuacion->id)
        ->assertSee('ESTADO HISTÓRICO')
        ->assertSee('(inactivo)');
});
