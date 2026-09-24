<?php

use App\Enums\TipoMovimientoFinanciero;
use App\Models\Accion;
use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\MovimientoFinanciero;
use App\Models\Submateria;
use App\Models\User;

test('una causa mantiene sus relaciones de clasificación y tribunal', function () {
    $materia = Materia::factory()->create();
    $submateria = Submateria::factory()->for($materia)->create();
    $ciudad = Ciudad::factory()->create();
    $juzgado = Juzgado::factory()->for($ciudad)->create();
    $estadoProcesal = EstadoProcesal::factory()->create();
    $estadoCausa = EstadoCausa::factory()->create();
    $direccion = Direccion::factory()->create();
    $responsable = User::factory()->create();
    $accion = Accion::factory()->create();

    $causa = Causa::factory()->create([
        'materia_id' => $materia,
        'submateria_id' => $submateria,
        'juzgado_id' => $juzgado,
        'estado_procesal_id' => $estadoProcesal,
        'estado_causa_id' => $estadoCausa,
        'direccion_id' => $direccion,
        'responsable_id' => $responsable,
        'accion_id' => $accion,
        'monto_demandado' => '1250000.50',
        'tiene_cotizaciones' => true,
    ]);

    expect($causa->materia->is($materia))->toBeTrue()
        ->and($causa->submateria->is($submateria))->toBeTrue()
        ->and($causa->juzgado->is($juzgado))->toBeTrue()
        ->and($causa->juzgado->ciudad->is($ciudad))->toBeTrue()
        ->and($causa->estadoProcesal->is($estadoProcesal))->toBeTrue()
        ->and($causa->estadoCausa->is($estadoCausa))->toBeTrue()
        ->and($causa->direccion->is($direccion))->toBeTrue()
        ->and($causa->responsable->is($responsable))->toBeTrue()
        ->and($responsable->causasAsignadas()->whereKey($causa)->exists())->toBeTrue()
        ->and($causa->accion->is($accion))->toBeTrue()
        ->and($causa->monto_demandado)->toBe('1250000.50')
        ->and($causa->tiene_cotizaciones)->toBeTrue()
        ->and($materia->causas()->whereKey($causa)->exists())->toBeTrue()
        ->and($submateria->causas()->whereKey($causa)->exists())->toBeTrue();
});

test('las actuaciones se ordenan cronológicamente y conservan su autor', function () {
    $causa = Causa::factory()->create();
    $usuario = User::factory()->create();

    Actuacion::factory()->for($causa)->create([
        'fecha' => '2025-12-15',
        'descripcion' => 'Archivar',
        'created_by' => $usuario,
    ]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2025-12-11',
        'descripcion' => 'Liquidación de costas',
        'created_by' => $usuario,
    ]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2025-12-12',
        'descripcion' => 'No ha lugar',
        'created_by' => $usuario,
    ]);

    expect($causa->actuaciones->pluck('descripcion')->all())->toBe([
        'Archivar',
        'No ha lugar',
        'Liquidación de costas',
    ])->and($causa->actuaciones->first()->creador->is($usuario))->toBeTrue()
        ->and($usuario->actuacionesCreadas()->count())->toBe(3);
});

test('los movimientos financieros usan enum y relacionan causa y autor', function () {
    $causa = Causa::factory()->create();
    $usuario = User::factory()->create();

    $movimiento = MovimientoFinanciero::factory()->for($causa)->create([
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'monto' => '98000.25',
        'created_by' => $usuario,
    ]);

    expect($movimiento->tipo)->toBe(TipoMovimientoFinanciero::Ingreso)
        ->and($movimiento->monto)->toBe('98000.25')
        ->and($movimiento->causa->is($causa))->toBeTrue()
        ->and($movimiento->creador->is($usuario))->toBeTrue()
        ->and($causa->movimientosFinancieros()->whereKey($movimiento)->exists())->toBeTrue();
});
