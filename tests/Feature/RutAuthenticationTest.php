<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Models\Causa;
use App\Models\User;
use App\Support\ChileanRut;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('usuario inicia sesión con RUT con puntos y contraseña correcta', function () {
    $user = User::factory()->create(['rut' => '12345678-5']);

    $this->post(route('login.store'), [
        'rut' => '12.345.678-5',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('usuario inicia sesión con contraseña que combina mayúsculas, minúsculas, números y caracteres especiales', function () {
    $password = 'Clave!Mixta2026#';
    $user = User::factory()->create(['rut' => '23456789-6', 'password' => $password]);

    $this->post(route('login.store'), [
        'rut' => '23.456.789-6',
        'password' => $password,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('usuario inicia sesión con RUT normalizado sin puntos', function () {
    $user = User::factory()->create(['rut' => '12345678-5']);

    $this->post(route('login.store'), [
        'rut' => '12345678-5',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('dígito verificador k minúscula se normaliza al autenticar', function () {
    $user = User::factory()->create(['rut' => '10000013-K']);

    $this->post(route('login.store'), [
        'rut' => '10.000.013-k',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('correo electrónico no puede utilizarse como credencial', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'rut' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('rut');

    $this->assertGuest();
});

test('RUT con dígito verificador incorrecto no autentica', function () {
    User::factory()->create(['rut' => '12345678-5']);

    $this->post(route('login.store'), [
        'rut' => '12.345.678-9',
        'password' => 'password',
    ])->assertSessionHasErrors('rut');

    $this->assertGuest();
});

test('RUT duplicado no puede crearse desde el mantenedor', function () {
    $administrador = User::factory()->administrador()->create();
    $existing = User::factory()->create(['rut' => '12345678-5']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Usuario duplicado')
        ->set('rut', ChileanRut::format($existing->rut))
        ->set('email', 'rut-duplicado@slad.local')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Consulta->value)
        ->call('saveUser')
        ->assertHasErrors(['rut' => 'unique']);
});

test('teléfono puede ser nulo al crear un usuario', function () {
    $administrador = User::factory()->administrador()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Usuario sin teléfono')
        ->set('rut', ChileanRut::fromBody('88000001'))
        ->set('email', 'sin-telefono@slad.local')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Consulta->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'sin-telefono@slad.local')->firstOrFail()->telefono)->toBeNull();
});

test('usuario puede crearse con teléfono y contraseña cifrada', function () {
    $administrador = User::factory()->administrador()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Usuario con teléfono')
        ->set('rut', ChileanRut::fromBody('88000002'))
        ->set('email', 'con-telefono@slad.local')
        ->set('telefono', '+56 9 8765 4321')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Abogado->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'con-telefono@slad.local')->firstOrFail();
    expect($user->telefono)->toBe('+56 9 8765 4321')
        ->and(Hash::check('Clave-Segura-2026', $user->password))->toBeTrue();
});

test('usuario inexistente no permite iniciar sesión', function () {
    $this->post(route('login.store'), [
        'rut' => '12.345.678-5',
        'password' => 'password',
    ])->assertSessionHasErrors('rut');

    $this->assertGuest();
});

test('sesión se regenera después de autenticar', function () {
    $user = User::factory()->create(['rut' => '12345678-5']);
    $this->get(route('login'));
    $sessionIdBeforeLogin = session()->getId();

    $this->post(route('login.store'), [
        'rut' => $user->rut,
        'password' => 'password',
    ]);

    expect(session()->getId())->not->toBe($sessionIdBeforeLogin);
    $this->assertAuthenticatedAs($user);
});

test('roles y permisos continúan funcionando después de autenticarse', function () {
    $administrador = User::factory()->administrador()->create(['rut' => '12345678-5']);

    $this->post(route('login.store'), [
        'rut' => $administrador->rut,
        'password' => 'password',
    ]);

    expect(auth()->user()->hasRole(RolUsuario::Administrador->value))->toBeTrue()
        ->and(auth()->user()->can(Permiso::RolesAsignarPermisos->value))->toBeTrue();
});

test('responsable de causa continúa relacionado mediante users id', function () {
    $responsable = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $responsable->id]);

    expect($causa->responsable_id)->toBe($responsable->id)
        ->and($causa->responsable->is($responsable))->toBeTrue();
});

test('edición permite conservar el mismo RUT', function () {
    $administrador = User::factory()->administrador()->create();
    $user = User::factory()->consulta()->create(['rut' => '12345678-5']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $user->id)
        ->set('name', 'Nombre actualizado')
        ->set('rut', '12.345.678-5')
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->refresh()->rut)->toBe('12345678-5');
});

test('edición no permite utilizar el RUT de otro usuario', function () {
    $administrador = User::factory()->administrador()->create();
    $existing = User::factory()->create(['rut' => '12345678-5']);
    $user = User::factory()->consulta()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $user->id)
        ->set('rut', ChileanRut::format($existing->rut))
        ->call('saveUser')
        ->assertHasErrors(['rut' => 'unique']);
});

test('buscador encuentra un usuario al escribir el RUT con puntos', function () {
    $administrador = User::factory()->administrador()->create();
    $user = User::factory()->create(['name' => 'Usuario encontrado', 'rut' => '12345678-5']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('search', '12.345.678-5')
        ->assertSee($user->name);
});

test('mantenedor rechaza RUT con dígito verificador incorrecto', function () {
    $administrador = User::factory()->administrador()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->set('name', 'Usuario inválido')
        ->set('rut', '12.345.678-9')
        ->set('email', 'rut-invalido@slad.local')
        ->set('password', 'Clave-Segura-2026')
        ->set('password_confirmation', 'Clave-Segura-2026')
        ->set('roleName', RolUsuario::Consulta->value)
        ->call('saveUser')
        ->assertHasErrors(['rut']);
});
