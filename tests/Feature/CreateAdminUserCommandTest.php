<?php

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('el comando crea un administrador activo y verificado', function () {
    $this->artisan('slad:create-admin')
        ->expectsQuestion('Nombre completo', 'Administradora SLAD')
        ->expectsQuestion('R.U.T.', '12.345.678-5')
        ->expectsQuestion('Correo electrónico', 'ADMIN@SLAD.LOCAL')
        ->expectsQuestion('Contraseña', 'Clave-Segura-2026')
        ->expectsQuestion('Confirmar contraseña', 'Clave-Segura-2026')
        ->expectsOutput('Administrador creado correctamente: admin@slad.local (12.345.678-5)')
        ->assertSuccessful();

    $administrador = User::query()->where('email', 'admin@slad.local')->firstOrFail();

    expect($administrador->hasRole(RolUsuario::Administrador->value))->toBeTrue()
        ->and($administrador->rut)->toBe('12345678-5')
        ->and($administrador->activo)->toBeTrue()
        ->and($administrador->debe_cambiar_password)->toBeFalse()
        ->and($administrador->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('Clave-Segura-2026', $administrador->password))->toBeTrue();
});

test('el comando rechaza un correo ya registrado', function () {
    $usuario = User::factory()->create();

    $this->artisan('slad:create-admin', [
        'rut' => '10.000.013-k',
        '--email' => $usuario->email,
        '--name' => 'Administradora SLAD',
    ])
        ->expectsQuestion('Contraseña', 'Clave-Segura-2026')
        ->expectsQuestion('Confirmar contraseña', 'Clave-Segura-2026')
        ->expectsOutput('No se pudo crear el administrador:')
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($usuario->fresh()->hasRole(RolUsuario::Consulta->value))->toBeTrue();
});
