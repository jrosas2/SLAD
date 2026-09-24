<?php

use App\Enums\RolUsuario;
use App\Models\Causa;
use App\Models\Materia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

test('la relación de responsables usa usuarios y retiró la tabla antigua', function () {
    $responsable = User::factory()->abogado()->create([]);
    $causas = Causa::factory()->count(2)->create();

    $causas->each(function (Causa $causa) use ($responsable): void {
        $causa->responsable()->associate($responsable);
        $causa->save();
    });

    expect(Schema::hasTable('responsables'))->toBeFalse()
        ->and($causas->first()->refresh()->responsable->is($responsable))->toBeTrue()
        ->and($responsable->causasAsignadas()->count())->toBe(2);
});

test('la migración conserva responsables legados como usuarios históricos inactivos', function () {
    $migration = require database_path('migrations/2026_08_26_005800_migrate_responsables_to_users.php');
    $migration->down();

    Schema::table('users', function ($table): void {
        $table->string('rol')->default(RolUsuario::Consulta->value);
    });

    DB::table('responsables')->insert([
        'id' => 900,
        'codigo' => 'LR',
        'nombres' => 'Laura',
        'apellidos' => 'Rojas',
        'activo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $causa = Causa::factory()->create(['responsable_id' => 900]);

    $migration->up();

    $responsable = User::query()->where('codigo', 'LR')->firstOrFail();
    $responsable->assignRole(RolUsuario::Abogado->value);

    Schema::table('users', function ($table): void {
        $table->dropColumn('rol');
    });

    expect($responsable->name)->toBe('Laura Rojas')
        ->and($responsable->activo)->toBeFalse()
        ->and($responsable->hasRole(RolUsuario::Abogado->value))->toBeTrue()
        ->and($causa->refresh()->responsable->is($responsable))->toBeTrue()
        ->and(Schema::hasTable('responsables'))->toBeFalse();
});

test('el formulario ofrece todos los usuarios activos como responsables', function () {
    $administrador = User::factory()->administrador()->create([]);
    $abogado = User::factory()->abogado()->create([
        'codigo' => 'FV',
        'name' => 'Francisco Vergara',

    ]);
    $inactivo = User::factory()->abogado()->create(['name' => 'Abogado Inactivo',  'activo' => false]);
    $consulta = User::factory()->consulta()->create(['name' => 'Usuario Consulta']);

    $this->actingAs($administrador);

    Livewire::test('pages::causas.form')
        ->assertSee($abogado->etiquetaResponsable())
        ->assertSee($consulta->etiquetaResponsable())
        ->assertDontSee($inactivo->name)
        ->assertHasNoErrors();
});

test('el administrador puede asignar cambiar y quitar un responsable', function () {
    $administrador = User::factory()->administrador()->create([]);
    $primero = User::factory()->consulta()->create([]);
    $segundo = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create();

    $this->actingAs($administrador);

    $component = Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('responsableId', (string) $primero->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->responsable->is($primero))->toBeTrue();

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('responsableId', (string) $segundo->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->responsable->is($segundo))->toBeTrue();

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('responsableId', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->responsable_id)->toBeNull();
});

test('el abogado crea causas sin responsable aunque manipule el estado livewire', function () {
    $abogado = User::factory()->abogado()->create([]);
    $responsable = User::factory()->abogado()->create([]);
    $materia = Materia::factory()->create();

    $this->actingAs($abogado);

    Livewire::test('pages::causas.form')
        ->assertSeeHtml('data-testid="responsable-readonly"')
        ->set('nombre', 'Causa creada por abogado')
        ->set('materiaId', (string) $materia->id)
        ->set('responsableId', (string) $responsable->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Causa::query()->where('nombre', 'Causa creada por abogado')->firstOrFail()->responsable_id)->toBeNull();
});

test('el abogado no puede cambiar ni quitar el responsable al editar otros campos', function () {
    $abogado = User::factory()->abogado()->create([]);
    $otroResponsable = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create(['nombre' => 'Nombre original', 'responsable_id' => $abogado->id]);

    $this->actingAs($abogado);

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('nombre', 'Nombre modificado')
        ->set('responsableId', (string) $otroResponsable->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->nombre)->toBe('Nombre modificado')
        ->and($causa->responsable->is($abogado))->toBeTrue();

    Livewire::test('pages::causas.form', ['causa' => $causa])
        ->set('responsableId', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->responsable->is($abogado))->toBeTrue();
});

test('la capacidad semántica de asignación es exclusiva del administrador', function () {
    $causa = Causa::factory()->create();
    $administrador = User::factory()->administrador()->create([]);
    $abogado = User::factory()->abogado()->create([]);
    $consulta = User::factory()->consulta()->create([]);

    expect(Gate::forUser($administrador)->allows('assignResponsible', $causa))->toBeTrue()
        ->and(Gate::forUser($abogado)->allows('assignResponsible', $causa))->toBeFalse()
        ->and(Gate::forUser($consulta)->allows('assignResponsible', $causa))->toBeFalse();
});
