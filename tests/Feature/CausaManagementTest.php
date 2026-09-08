<?php

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
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function catalogosCompletosParaCausa(): array
{
    $materia = Materia::factory()->create(['nombre' => 'Derecho Civil']);
    $submateria = Submateria::factory()->for($materia)->create(['nombre' => 'Cobranza']);
    $ciudad = Ciudad::factory()->create(['nombre' => 'SANTIAGO']);
    $juzgado = Juzgado::factory()->for($ciudad)->create(['nombre' => 'Primer Juzgado Civil']);
    $responsable = User::factory()->abogado()->create([
        'codigo' => 'RESP-01',
        'name' => 'Ana Pérez',

    ]);
    $estadoProcesal = EstadoProcesal::factory()->create(['nombre' => 'DISCUSIÓN']);
    $estadoCausa = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    $accion = Accion::factory()->create(['nombre' => 'Demanda ejecutiva']);
    $direccion = Direccion::factory()->create(['nombre' => 'JURIDICA']);

    return compact(
        'materia',
        'submateria',
        'ciudad',
        'juzgado',
        'responsable',
        'estadoProcesal',
        'estadoCausa',
        'accion',
        'direccion',
    );
}

test('el administrador puede listar y visualizar causas', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create([
        'nombre' => 'Expediente visible',
        'numero_causa' => 'C-100-2026',
        'demandante_demandado' => 'EMPRESA EJEMPLO SPA',
    ]);

    $this->actingAs($administrador)
        ->get(route('causas.index'))
        ->assertOk()
        ->assertSee('C-100-2026')
        ->assertSee('Expediente visible')
        ->assertSee('EMPRESA EJEMPLO SPA')
        ->assertSeeHtml('id="causas-list"')
        ->assertSee('Ver')
        ->assertSee('Editar')
        ->assertSee('Eliminar');

    $this->get(route('causas.show', $causa))
        ->assertOk()
        ->assertSee('Resumen del expediente');
});

test('el formulario muestra dirección antes de materia en clasificación', function () {
    $administrador = User::factory()->administrador()->create();

    $this->actingAs($administrador)
        ->get(route('causas.create'))
        ->assertOk()
        ->assertSeeInOrder(['Dirección', 'Materia']);
});

test('el administrador crea una causa con todas sus relaciones y monto numérico', function () {
    $administrador = User::factory()->administrador()->create([]);
    $catalogos = catalogosCompletosParaCausa();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->set('nombre', 'Municipalidad con Proveedor')
        ->set('numeroCausa', 'C-200-2026')
        ->set('fechaCausa', '2026-01-10')
        ->set('fechaIngreso', '2026-01-12')
        ->set('ciudadId', (string) $catalogos['ciudad']->id)
        ->set('juzgadoId', (string) $catalogos['juzgado']->id)
        ->set('materiaId', (string) $catalogos['materia']->id)
        ->set('submateriaId', (string) $catalogos['submateria']->id)
        ->set('accionId', (string) $catalogos['accion']->id)
        ->set('estadoProcesalId', (string) $catalogos['estadoProcesal']->id)
        ->set('estadoCausaId', (string) $catalogos['estadoCausa']->id)
        ->set('direccionId', (string) $catalogos['direccion']->id)
        ->set('demandanteDemandado', 'Proveedor Municipal SPA')
        ->set('responsableId', (string) $catalogos['responsable']->id)
        ->set('montoDemandado', '1250000')
        ->set('tieneCotizaciones', true)
        ->set('observacionImportante', 'Revisar plazo de contestación.')
        ->call('save')
        ->assertHasNoErrors();

    $causa = Causa::query()->where('numero_causa', 'C-200-2026')->firstOrFail();

    expect($causa->materia->is($catalogos['materia']))->toBeTrue()
        ->and($causa->submateria->is($catalogos['submateria']))->toBeTrue()
        ->and($causa->juzgado->is($catalogos['juzgado']))->toBeTrue()
        ->and($causa->responsable->is($catalogos['responsable']))->toBeTrue()
        ->and($causa->estadoProcesal->is($catalogos['estadoProcesal']))->toBeTrue()
        ->and($causa->estadoCausa->is($catalogos['estadoCausa']))->toBeTrue()
        ->and($causa->accion->is($catalogos['accion']))->toBeTrue()
        ->and($causa->direccion->is($catalogos['direccion']))->toBeTrue()
        ->and($causa->demandante_demandado)->toBe('Proveedor Municipal SPA')
        ->and($causa->monto_demandado)->toBe('1250000.00')
        ->and($causa->tiene_cotizaciones)->toBeTrue();
});

test('al editar una causa se cargan sus datos de clasificación y monto', function () {
    $administrador = User::factory()->administrador()->create();
    $direccion = Direccion::factory()->create();
    $causa = Causa::factory()->create([
        'monto_demandado' => 39999999,
        'direccion_id' => $direccion,
        'demandante_demandado' => 'CONTRAPARTE DE PRUEBA',
    ]);

    Livewire::actingAs($administrador)
        ->test('pages::causas.form', ['causa' => $causa])
        ->assertSet('montoDemandado', '39999999')
        ->assertSet('direccionId', (string) $direccion->id)
        ->assertSet('demandanteDemandado', 'CONTRAPARTE DE PRUEBA');
});

test('el monto demandado no acepta decimales', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();

    Livewire::actingAs($administrador)
        ->test('pages::causas.form', ['causa' => $causa])
        ->set('montoDemandado', '39999999.50')
        ->call('save')
        ->assertHasErrors(['montoDemandado' => 'integer']);
});

test('las causas no almacenan funcionario o usuario', function () {
    expect(Schema::hasColumn('causas', 'funcionario_usuario'))->toBeFalse();
});

test('las causas almacenan el demandante o demandado', function () {
    expect(Schema::hasColumn('causas', 'demandante_demandado'))->toBeTrue();
});

test('el número de causa puede repetirse', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materia = Materia::factory()->create();
    Causa::factory()->for($materia)->create(['numero_causa' => 'ROL-REPETIDO']);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->set('nombre', 'Segunda causa con el mismo rol')
        ->set('numeroCausa', 'ROL-REPETIDO')
        ->set('materiaId', (string) $materia->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Causa::query()->where('numero_causa', 'ROL-REPETIDO')->count())->toBe(2);
});

test('el administrador puede editar una causa y cambiar sus estados', function () {
    $administrador = User::factory()->administrador()->create([]);
    $estadoInicial = EstadoCausa::factory()->create(['nombre' => 'VIGENTE']);
    $estadoFinal = EstadoCausa::factory()->create(['nombre' => 'CERRADA']);
    $causa = Causa::factory()->create(['estado_causa_id' => $estadoInicial]);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('nombre', 'Causa actualizada')
        ->set('estadoCausaId', (string) $estadoFinal->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->nombre)->toBe('Causa actualizada')
        ->and($causa->estadoCausa->is($estadoFinal))->toBeTrue();
});

test('el usuario consulta puede listar y ver pero no crear ni editar causas', function () {
    $consulta = User::factory()->consulta()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $consulta->id]);
    $this->actingAs($consulta);

    $this->get(route('causas.index'))->assertOk();
    $this->get(route('causas.show', $causa))->assertOk();
    $this->get(route('causas.create'))->assertForbidden();
    $this->get(route('causas.edit', $causa))->assertForbidden();
});

test('el abogado puede crear y editar pero no eliminar causas', function () {
    $abogado = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $this->actingAs($abogado);

    $this->get(route('causas.create'))->assertOk();
    $this->get(route('causas.edit', $causa))->assertOk();

    Livewire::test('pages::causas.index')
        ->call('requestDelete', $causa->id)
        ->assertForbidden();
});

test('la submateria debe pertenecer a la materia seleccionada', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materia = Materia::factory()->create();
    $otraMateria = Materia::factory()->create();
    $submateriaAjena = Submateria::factory()->for($otraMateria)->create();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->set('nombre', 'Causa inválida')
        ->set('materiaId', (string) $materia->id)
        ->set('submateriaId', (string) $submateriaAjena->id)
        ->call('save')
        ->assertHasErrors(['submateriaId' => 'exists']);
});

test('el juzgado debe pertenecer a la ciudad seleccionada', function () {
    $administrador = User::factory()->administrador()->create([]);
    $ciudad = Ciudad::factory()->create();
    $otraCiudad = Ciudad::factory()->create();
    $juzgadoAjeno = Juzgado::factory()->for($otraCiudad)->create();
    $materia = Materia::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->set('nombre', 'Causa con tribunal inválido')
        ->set('materiaId', (string) $materia->id)
        ->set('ciudadId', (string) $ciudad->id)
        ->set('juzgadoId', (string) $juzgadoAjeno->id)
        ->call('save')
        ->assertHasErrors(['juzgadoId' => 'exists']);
});

test('el listado busca por número de causa', function () {
    $administrador = User::factory()->administrador()->create([]);
    Causa::factory()->create(['nombre' => 'Causa objetivo', 'numero_causa' => 'UNICO-7788']);
    Causa::factory()->create(['nombre' => 'Causa excluida', 'numero_causa' => 'OTRO-9911']);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->set('search', '7788')
        ->assertSee('Causa objetivo')
        ->assertDontSee('Causa excluida');
});

test('el listado filtra por materia', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materiaObjetivo = Materia::factory()->create();
    $otraMateria = Materia::factory()->create();
    Causa::factory()->for($materiaObjetivo)->create(['nombre' => 'Materia visible']);
    Causa::factory()->for($otraMateria)->create(['nombre' => 'Materia oculta']);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->set('materiaFilter', (string) $materiaObjetivo->id)
        ->assertSee('Materia visible')
        ->assertDontSee('Materia oculta');
});

test('el listado filtra por responsable', function () {
    $administrador = User::factory()->administrador()->create([]);
    $responsableObjetivo = User::factory()->abogado()->create([]);
    $otroResponsable = User::factory()->abogado()->create([]);
    Causa::factory()->create(['nombre' => 'Responsable visible', 'responsable_id' => $responsableObjetivo]);
    Causa::factory()->create(['nombre' => 'Responsable oculto', 'responsable_id' => $otroResponsable]);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->set('responsableFilter', (string) $responsableObjetivo->id)
        ->assertSee('Responsable visible')
        ->assertDontSee('Responsable oculto');
});

test('el listado filtra por estado de causa', function () {
    $administrador = User::factory()->administrador()->create([]);
    $estadoObjetivo = EstadoCausa::factory()->create();
    $otroEstado = EstadoCausa::factory()->create();
    Causa::factory()->create(['nombre' => 'Estado visible', 'estado_causa_id' => $estadoObjetivo]);
    Causa::factory()->create(['nombre' => 'Estado oculto', 'estado_causa_id' => $otroEstado]);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->set('estadoCausaFilter', (string) $estadoObjetivo->id)
        ->assertSee('Estado visible')
        ->assertDontSee('Estado oculto');
});

test('el listado filtra por dirección y demandante o demandado', function () {
    $administrador = User::factory()->administrador()->create([]);
    $direccionObjetivo = Direccion::factory()->create(['nombre' => 'JURIDICA']);
    $otraDireccion = Direccion::factory()->create(['nombre' => 'SALUD']);
    Causa::factory()->create([
        'nombre' => 'Causa dirección y parte visible',
        'direccion_id' => $direccionObjetivo,
        'demandante_demandado' => 'SOCIEDAD OBJETIVO SPA',
    ]);
    Causa::factory()->create([
        'nombre' => 'Causa dirección y parte oculta',
        'direccion_id' => $otraDireccion,
        'demandante_demandado' => 'OTRA SOCIEDAD SPA',
    ]);
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->set('direccionFilter', (string) $direccionObjetivo->id)
        ->set('demandanteDemandadoFilter', 'OBJETIVO')
        ->assertSee('Causa dirección y parte visible')
        ->assertDontSee('Causa dirección y parte oculta');
});

test('al cambiar materia o ciudad se limpian dependencias incompatibles', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materia = Materia::factory()->create();
    $otraMateria = Materia::factory()->create();
    $submateria = Submateria::factory()->for($materia)->create();
    $ciudad = Ciudad::factory()->create();
    $otraCiudad = Ciudad::factory()->create();
    $juzgado = Juzgado::factory()->for($ciudad)->create();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->set('materiaId', (string) $materia->id)
        ->set('submateriaId', (string) $submateria->id)
        ->set('materiaId', (string) $otraMateria->id)
        ->assertSet('submateriaId', '')
        ->set('ciudadId', (string) $ciudad->id)
        ->set('juzgadoId', (string) $juzgado->id)
        ->set('ciudadId', (string) $otraCiudad->id)
        ->assertSet('juzgadoId', '');
});

test('el administrador elimina causas mediante borrado lógico', function () {
    $administrador = User::factory()->administrador()->create([]);
    $causa = Causa::factory()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.index')
        ->call('requestDelete', $causa->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Causa::query()->find($causa->id))->toBeNull()
        ->and(Causa::withTrashed()->findOrFail($causa->id)->trashed())->toBeTrue();
});

test('al editar se mantiene visible un catálogo inactivo ya relacionado', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materiaInactiva = Materia::factory()->create(['nombre' => 'Materia histórica', 'activo' => false]);
    $causa = Causa::factory()->for($materiaInactiva)->create();
    $this->actingAs($administrador);

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->assertSee('Materia histórica')
        ->assertSee('(inactiva)');
});
