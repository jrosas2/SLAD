<?php

use App\Enums\Permiso;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('el administrador dispone de todos los permisos base', function () {
    $administrador = User::factory()->administrador()->create([]);

    foreach (Permiso::cases() as $permiso) {
        expect(Gate::forUser($administrador)->allows($permiso->value))->toBeTrue();
    }
});

test('el abogado puede operar causas pero no administrar usuarios', function () {
    $abogado = User::factory()->abogado()->create([]);

    expect(Gate::forUser($abogado)->allows(Permiso::CausasCrear->value))->toBeTrue()
        ->and(Gate::forUser($abogado)->allows(Permiso::ActuacionesCrear->value))->toBeTrue()
        ->and(Gate::forUser($abogado)->allows(Permiso::UsuariosVer->value))->toBeFalse();
});

test('el usuario de consulta es de solo lectura y un usuario inactivo no accede', function () {
    $consulta = User::factory()->consulta()->create([]);
    $inactivo = User::factory()->administrador()->create([

        'activo' => false,
    ]);

    expect(Gate::forUser($consulta)->allows(Permiso::CausasVer->value))->toBeTrue()
        ->and(Gate::forUser($consulta)->allows(Permiso::CausasEditar->value))->toBeFalse()
        ->and(Gate::forUser($inactivo)->allows(Permiso::CausasVer->value))->toBeFalse();
});
