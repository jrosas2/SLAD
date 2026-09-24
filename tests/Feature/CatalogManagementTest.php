<?php

use App\Models\Ciudad;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Livewire\Livewire;

test('los usuarios activos pueden consultar catálogos y solo el administrador ve acciones de gestión', function () {
    $administrador = User::factory()->administrador()->create([]);
    $abogado = User::factory()->abogado()->create([]);
    $consulta = User::factory()->consulta()->create([]);

    $this->actingAs($administrador)
        ->get(route('catalogos.simple.index', ['catalogo' => 'materias']))
        ->assertOk()
        ->assertSee('Nueva materia');

    $this->actingAs($abogado)
        ->get(route('catalogos.juzgados.index'))
        ->assertOk()
        ->assertDontSee('Nuevo juzgado');

    $this->actingAs($consulta)
        ->get(route('catalogos.simple.index', ['catalogo' => 'materias']))
        ->assertOk()
        ->assertDontSee('Nueva materia');
});

test('cada catálogo simple dispone de una página de consulta', function (string $catalogo, string $titulo) {
    $usuario = User::factory()->consulta()->create([]);

    $this->actingAs($usuario)
        ->get(route('catalogos.simple.index', ['catalogo' => $catalogo]))
        ->assertOk()
        ->assertSee($titulo);
})->with([
    'materias' => ['materias', 'Materias'],
    'ciudades' => ['ciudades', 'Ciudades'],
    'direcciones' => ['direcciones', 'Direcciones'],
    'estados procesales' => ['estados-procesales', 'Estados procesales'],
    'estados de causa' => ['estados-causa', 'Estados de causa'],
    'acciones' => ['acciones', 'Acciones'],
]);

test('el administrador crea edita y desactiva una materia', function () {
    $administrador = User::factory()->administrador()->create([]);

    $this->actingAs($administrador);

    $component = Livewire::test('pages::catalogos.simple', ['catalogo' => 'materias'])
        ->set('nombre', 'Derecho Administrativo')
        ->call('save')
        ->assertHasNoErrors();

    $materia = Materia::query()->where('nombre', 'Derecho Administrativo')->firstOrFail();

    $component
        ->call('openEditModal', $materia->id)
        ->set('nombre', 'Derecho Público')
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggleStatus', $materia->id);

    expect($materia->refresh()->nombre)->toBe('Derecho Público')
        ->and($materia->activo)->toBeFalse();
});

test('un usuario sin permiso de administración no puede modificar catálogos', function () {
    $consulta = User::factory()->consulta()->create([]);

    $this->actingAs($consulta);

    Livewire::test('pages::catalogos.simple', ['catalogo' => 'materias'])
        ->set('nombre', 'Materia no autorizada')
        ->call('save')
        ->assertForbidden();

    expect(Materia::query()->where('nombre', 'Materia no autorizada')->exists())->toBeFalse();
});

test('los nombres de catálogos simples no pueden duplicarse', function () {
    $administrador = User::factory()->administrador()->create([]);
    Materia::factory()->create(['nombre' => 'Derecho Civil']);

    $this->actingAs($administrador);

    Livewire::test('pages::catalogos.simple', ['catalogo' => 'materias'])
        ->set('nombre', 'Derecho Civil')
        ->call('save')
        ->assertHasErrors(['nombre' => 'unique']);
});

test('una submateria pertenece a una materia y no se duplica dentro de ella', function () {
    $administrador = User::factory()->administrador()->create([]);
    $materia = Materia::factory()->create();
    $otraMateria = Materia::factory()->create();

    $this->actingAs($administrador);

    Livewire::test('pages::catalogos.submaterias')
        ->set('materiaId', (string) $materia->id)
        ->set('nombre', 'Cobranza judicial')
        ->call('save')
        ->assertHasNoErrors();

    $submateria = Submateria::query()->where('nombre', 'Cobranza judicial')->firstOrFail();

    expect($submateria->materia->is($materia))->toBeTrue();

    Livewire::test('pages::catalogos.submaterias')
        ->set('materiaId', (string) $materia->id)
        ->set('nombre', 'Cobranza judicial')
        ->call('save')
        ->assertHasErrors(['nombre' => 'unique']);

    Livewire::test('pages::catalogos.submaterias')
        ->set('materiaId', (string) $otraMateria->id)
        ->set('nombre', 'Cobranza judicial')
        ->call('save')
        ->assertHasNoErrors();
});

test('un juzgado se registra en su ciudad y el listado puede filtrarse por ella', function () {
    $administrador = User::factory()->administrador()->create([]);
    $santiago = Ciudad::factory()->create(['nombre' => 'Santiago']);
    $valparaiso = Ciudad::factory()->create(['nombre' => 'Valparaíso']);
    Juzgado::factory()->for($valparaiso)->create(['nombre' => 'Juzgado de Valparaíso']);

    $this->actingAs($administrador);

    Livewire::test('pages::catalogos.juzgados')
        ->set('ciudadId', (string) $santiago->id)
        ->set('nombre', 'Tercer Juzgado Civil')
        ->call('save')
        ->assertHasNoErrors()
        ->set('ciudadFilter', (string) $santiago->id)
        ->assertSee('Tercer Juzgado Civil')
        ->assertDontSee('Juzgado de Valparaíso');

    expect(Juzgado::query()->where('nombre', 'Tercer Juzgado Civil')->firstOrFail()->ciudad->is($santiago))->toBeTrue();
});
