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

test('un usuario autorizado visualiza el detalle imprimible completo de una causa', function () {
    $administrador = User::factory()->administrador()->create(['name' => 'Administradora SLAD']);
    $responsable = User::factory()->abogado()->create(['name' => 'Abogada Responsable', 'codigo' => 'AB-01']);
    $materia = Materia::factory()->create(['nombre' => 'CIVIL']);
    $submateria = Submateria::factory()->for($materia)->create(['nombre' => 'COBRANZA MUNICIPAL']);
    $ciudad = Ciudad::factory()->create(['nombre' => 'TEMUCO']);
    $juzgado = Juzgado::factory()->for($ciudad)->create(['nombre' => 'Juzgado Civil de Temuco']);
    $direccion = Direccion::factory()->create(['nombre' => 'JURÍDICA']);
    $estadoProcesal = EstadoProcesal::factory()->create(['nombre' => 'PRUEBA']);
    $estadoCausa = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    $accion = Accion::factory()->create(['nombre' => 'DEMANDA']);
    $causa = Causa::factory()->create([
        'nombre' => 'Cobro de derechos municipales',
        'numero_causa' => 'C-88-2026',
        'fecha_causa' => '2026-02-10',
        'fecha_ingreso' => '2026-02-11',
        'juzgado_id' => $juzgado,
        'direccion_id' => $direccion,
        'materia_id' => $materia,
        'submateria_id' => $submateria,
        'estado_procesal_id' => $estadoProcesal,
        'estado_causa_id' => $estadoCausa,
        'accion_id' => $accion,
        'responsable_id' => $responsable,
        'monto_demandado' => 1500000,
        'tiene_cotizaciones' => true,
        'observacion_importante' => 'Verificar plazo de apelación.',
    ]);
    Actuacion::factory()->for($causa)->create([
        'fecha' => '2026-03-01',
        'estado_procesal_id' => $estadoProcesal,
        'descripcion' => 'Se recibe prueba documental.',
        'created_by' => $administrador,
    ]);
    MovimientoFinanciero::factory()->for($causa)->create([
        'fecha' => '2026-03-02',
        'tipo' => TipoMovimientoFinanciero::Ingreso,
        'monto' => 250000,
        'observacion' => 'Pago parcial.',
    ]);

    $this->actingAs($administrador)
        ->get(route('causas.show', $causa))
        ->assertOk()
        ->assertSee('Imprimir')
        ->assertSee('SLAD')
        ->assertSee('Detalle de causa')
        ->assertSee('Cobro de derechos municipales')
        ->assertSee('C-88-2026')
        ->assertSee('Juzgado Civil de Temuco')
        ->assertSee('JURÍDICA')
        ->assertSee('Abogada Responsable')
        ->assertSee('Verificar plazo de apelación.')
        ->assertSee('Se recibe prueba documental.')
        ->assertSee('Pago parcial.')
        ->assertSee('$1.500.000')
        ->assertSee('$250.000')
        ->assertSee('Total ingresos')
        ->assertSee('window.print()')
        ->assertSee('printable-cause')
        ->assertSee('size: 8.5in 13in')
        ->assertSee('slad-main');
});

test('un usuario sin permiso para ver causas no accede al detalle imprimible', function () {
    $usuarioSinPermisos = User::factory()->create();
    $usuarioSinPermisos->syncRoles([]);
    $causa = Causa::factory()->create();

    $this->actingAs($usuarioSinPermisos)
        ->get(route('causas.show', $causa))
        ->assertForbidden();
});

test('el listado imprime todas las causas que cumplen los filtros aplicados', function () {
    $administrador = User::factory()->administrador()->create();
    $direccion = Direccion::factory()->create(['nombre' => 'JURÍDICA']);
    Causa::factory()->create(['nombre' => 'Causa incluida', 'numero_causa' => 'C-99-2026', 'direccion_id' => $direccion]);
    Causa::factory()->create(['nombre' => 'Causa excluida', 'numero_causa' => 'C-98-2026']);

    $this->actingAs($administrador)
        ->get(route('causas.index', ['search' => 'incluida', 'imprimir' => 1]))
        ->assertOk()
        ->assertSee('Imprimir listado')
        ->assertSee('Listado de causas')
        ->assertSee('Causa incluida')
        ->assertSee('JURÍDICA')
        ->assertDontSee('Causa excluida')
        ->assertSee('Total de causas: 1')
        ->assertSee('window.setTimeout')
        ->assertSee('size: 13in 8.5in')
        ->assertSee('slad-main');
});
