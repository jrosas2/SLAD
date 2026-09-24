<?php

use App\Enums\RolUsuario;
use App\Models\User;
use App\Support\ChileanRut;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('solo el administrador puede abrir la administración de usuarios', function () {
    $administrador = User::factory()->administrador()->create([]);
    $abogado = User::factory()->abogado()->create([]);

    $this->actingAs($administrador)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Usuarios y roles')
        ->assertSee('Nueva clave')
        ->assertSee('data-preserve-case', escape: false)
        ->assertDontSee('Código histórico');

    $this->actingAs($abogado)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('el administrador crea un usuario con rol y contraseña temporal única', function () {
    $administrador = User::factory()->administrador()->create([]);

    $this->actingAs($administrador);

    $component = Livewire::test('pages::admin.users.index')
        ->set('name', 'Abogada Nueva')
        ->set('rut', '12.345.678-5')
        ->set('email', 'ABOGADA@SLAD.LOCAL')
        ->set('telefono', '+56 9 1234 5678')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Abogado->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    $usuario = User::query()->where('email', 'abogada@slad.local')->firstOrFail();

    expect($usuario->codigo)->toBeNull()
        ->and($usuario->rut)->toBe('12345678-5')
        ->and($usuario->rutFormateado())->toBe('12.345.678-5')
        ->and($usuario->telefono)->toBe('+56 9 1234 5678')
        ->and($usuario->hasRole(RolUsuario::Abogado->value))->toBeTrue()
        ->and($usuario->activo)->toBeTrue()
        ->and($usuario->debe_cambiar_password)->toBeTrue()
        ->and($usuario->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('Clave-Segura-2026', $usuario->password))->toBeTrue();
});

test('el código histórico de usuario es opcional y único', function () {
    $administrador = User::factory()->administrador()->create([]);
    User::factory()->create(['codigo' => 'FV']);

    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Código duplicado')
        ->set('codigo', 'fv')
        ->set('rut', ChileanRut::fromBody('88000003'))
        ->set('email', 'codigo-duplicado@slad.local')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Abogado->value)
        ->call('saveUser')
        ->assertHasErrors(['codigo' => 'unique']);
});

test('el correo de un usuario debe ser único', function () {
    $administrador = User::factory()->administrador()->create([]);
    $existente = User::factory()->create();

    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Usuario Duplicado')
        ->set('rut', ChileanRut::fromBody('88000004'))
        ->set('email', $existente->email)
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Consulta->value)
        ->call('saveUser')
        ->assertHasErrors(['email' => 'unique']);
});

test('el administrador cambia el rol y estado de otro usuario', function () {
    $administrador = User::factory()->administrador()->create([]);
    $usuario = User::factory()->consulta()->create([

        'activo' => true,
    ]);

    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $usuario->id)
        ->set('roleName', RolUsuario::Abogado->value)
        ->set('activo', false)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($usuario->refresh()->hasRole(RolUsuario::Abogado->value))->toBeTrue()
        ->and($usuario->activo)->toBeFalse();
});

test('el administrador regenera la contraseña temporal de un usuario', function () {
    $administrador = User::factory()->administrador()->create([]);
    $usuario = User::factory()->create([
        'password' => 'Clave-Anterior-2026!',
        'debe_cambiar_password' => false,
        'remember_token' => 'token-anterior',
    ]);

    $this->actingAs($administrador);

    $component = Livewire::test('pages::admin.users.index')
        ->call('openPasswordResetModal', $usuario->id)
        ->assertSet('showPasswordResetModal', true)
        ->assertSet('passwordResetUserName', $usuario->name)
        ->call('regenerateTemporaryPassword')
        ->assertHasNoErrors()
        ->assertSet('showPasswordResetModal', false);

    $temporaryPassword = $component->get('temporaryPassword');
    $usuario->refresh();

    expect($temporaryPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check('Clave-Anterior-2026!', $usuario->password))->toBeFalse()
        ->and(Hash::check($temporaryPassword, $usuario->password))->toBeTrue()
        ->and($usuario->debe_cambiar_password)->toBeTrue()
        ->and($usuario->remember_token)->not->toBe('token-anterior');

    $component->assertSee($temporaryPassword);
});

test('el administrador no puede quitarse su rol ni desactivar su cuenta', function () {
    $administrador = User::factory()->administrador()->create([]);

    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $administrador->id)
        ->set('roleName', RolUsuario::Consulta->value)
        ->set('activo', false)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($administrador->refresh()->hasRole(RolUsuario::Administrador->value))->toBeTrue()
        ->and($administrador->activo)->toBeTrue();
});
