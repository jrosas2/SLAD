<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Models\Causa;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('1 administrador puede crear un rol', function () {
    $administrador = User::factory()->administrador()->create();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->set('name', 'supervisor')
        ->set('description', 'Supervisión jurídica')
        ->call('saveRole')
        ->assertHasNoErrors();

    expect(Role::findByName('SUPERVISOR')->description)->toBe('Supervisión jurídica');
});

test('2 administrador puede editar un rol', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'DIGITADOR', 'guard_name' => 'web']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('name', 'DIGITADOR JURÍDICO')
        ->set('description', 'Ingreso de causas')
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->refresh()->name)->toBe('DIGITADOR JURÍDICO');
});

test('3 administrador puede asignar permisos', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'FINANZAS', 'guard_name' => 'web']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('selectedPermissions', [Permiso::MovimientosVer->value, Permiso::MovimientosCrear->value])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->refresh()->hasAllPermissions([Permiso::MovimientosVer->value, Permiso::MovimientosCrear->value]))->toBeTrue();
});

test('4 usuario sin permiso no puede administrar roles', function () {
    $consulta = User::factory()->consulta()->create();
    $this->actingAs($consulta);

    Livewire::test('pages::admin.roles.index')->assertForbidden();
});

test('5 un rol puede tener múltiples permisos', function () {
    $role = Role::create(['name' => 'AUDITOR', 'guard_name' => 'web']);
    $role->syncPermissions([Permiso::DashboardVer->value, Permiso::CausasVer->value, Permiso::ActuacionesVer->value]);

    expect($role->permissions)->toHaveCount(3);
});

test('6 usuario obtiene permisos mediante su rol', function () {
    $role = Role::create(['name' => 'SUPERVISOR', 'guard_name' => 'web']);
    $role->givePermissionTo(Permiso::CausasEditar->value);
    $usuario = User::factory()->create();
    $usuario->syncRoles([$role]);

    expect($usuario->can(Permiso::CausasEditar->value))->toBeTrue();
});

test('7 quitar un permiso al rol quita capacidad al usuario', function () {
    $role = Role::create(['name' => 'SUPERVISOR', 'guard_name' => 'web']);
    $role->givePermissionTo(Permiso::CausasEditar->value);
    $usuario = User::factory()->create();
    $usuario->syncRoles([$role]);
    expect($usuario->can(Permiso::CausasEditar->value))->toBeTrue();

    $role->revokePermissionTo(Permiso::CausasEditar->value);

    expect($usuario->can(Permiso::CausasEditar->value))->toBeFalse();
});

test('8 navegación respeta permisos', function () {
    $consulta = User::factory()->consulta()->create();

    $this->actingAs($consulta)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Roles y permisos')
        ->assertDontSee('Usuarios');
});

test('9 backend rechaza acceso directo sin permiso', function () {
    $consulta = User::factory()->consulta()->create();

    $this->actingAs($consulta)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

test('10 administrador puede asignar un rol dinámico a un usuario', function () {
    $administrador = User::factory()->administrador()->create();
    $usuario = User::factory()->consulta()->create();
    $role = Role::create(['name' => 'JEFE JURÍDICO', 'guard_name' => 'web']);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $usuario->id)
        ->set('roleName', $role->name)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($usuario->refresh()->hasRole($role))->toBeTrue();
});

test('11 usuario sin permiso no puede asignar roles', function () {
    $gestor = Role::create(['name' => 'GESTOR USUARIOS', 'guard_name' => 'web']);
    $gestor->syncPermissions([Permiso::UsuariosVer->value, Permiso::UsuariosEditar->value]);
    $actor = User::factory()->create();
    $actor->syncRoles([$gestor]);
    $usuario = User::factory()->consulta()->create();
    $this->actingAs($actor);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $usuario->id)
        ->set('roleName', RolUsuario::Abogado->value)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($usuario->refresh()->hasRole(RolUsuario::Consulta->value))->toBeTrue();
});

test('12 no puede eliminarse el último administrador funcional', function () {
    $gestor = Role::create(['name' => 'GESTOR USUARIOS', 'guard_name' => 'web']);
    $gestor->syncPermissions([
        Permiso::UsuariosVer->value,
        Permiso::UsuariosEditar->value,
        Permiso::UsuariosDesactivar->value,
        Permiso::UsuariosAsignarRoles->value,
    ]);
    $actor = User::factory()->create();
    $actor->syncRoles([$gestor]);
    $ultimoAdministrador = User::factory()->administrador()->create();
    $this->actingAs($actor);

    Livewire::test('pages::admin.users.index')
        ->call('openEditModal', $ultimoAdministrador->id)
        ->set('roleName', RolUsuario::Consulta->value)
        ->call('saveUser')
        ->assertHasErrors(['roleName']);

    expect($ultimoAdministrador->refresh()->hasRole(RolUsuario::Administrador->value))->toBeTrue();
});

test('13 abogado no puede asignar responsable', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create();

    expect(Gate::forUser($abogado)->allows('assignResponsible', $causa))->toBeFalse();
});

test('14 administrador puede asignar responsable', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();

    expect(Gate::forUser($administrador)->allows('assignResponsible', $causa))->toBeTrue();
});

test('15 administrador puede delegar y revocar permisos de asignación a otros roles', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'SUPERVISOR', 'guard_name' => 'web']);
    $usuario = User::factory()->create();
    $usuario->syncRoles([$role]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('selectedPermissions', [Permiso::CausasAsignarResponsable->value, Permiso::RecordatoriosAsignar->value])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->refresh()->hasAllPermissions([Permiso::CausasAsignarResponsable->value, Permiso::RecordatoriosAsignar->value]))->toBeTrue()
        ->and($usuario->can(Permiso::CausasAsignarResponsable->value))->toBeTrue()
        ->and($usuario->can(Permiso::RecordatoriosAsignar->value))->toBeTrue();

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('selectedPermissions', [])
        ->call('saveRole')
        ->assertHasNoErrors();

    expect($role->refresh()->hasAnyPermission([Permiso::CausasAsignarResponsable->value, Permiso::RecordatoriosAsignar->value]))->toBeFalse()
        ->and($usuario->can(Permiso::CausasAsignarResponsable->value))->toBeFalse()
        ->and($usuario->can(Permiso::RecordatoriosAsignar->value))->toBeFalse();
});

test('16 permisos se actualizan inmediatamente después de modificar un rol', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'DIGITADOR', 'guard_name' => 'web']);
    $role->givePermissionTo(Permiso::CausasVer->value);
    $usuario = User::factory()->create();
    $usuario->syncRoles([$role]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('selectedPermissions', [Permiso::CausasCrear->value])
        ->call('saveRole');

    expect($usuario->can(Permiso::CausasVer->value))->toBeFalse()
        ->and($usuario->can(Permiso::CausasCrear->value))->toBeTrue();
});

test('17 cache de permisos se invalida al sincronizar el rol', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'AUDITOR', 'guard_name' => 'web']);
    $usuario = User::factory()->create();
    $usuario->syncRoles([$role]);
    expect($usuario->can(Permiso::ActuacionesVer->value))->toBeFalse();
    app(PermissionRegistrar::class)->getPermissions();
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('openEditModal', $role->id)
        ->set('selectedPermissions', [Permiso::ActuacionesVer->value])
        ->call('saveRole');

    expect($usuario->can(Permiso::ActuacionesVer->value))->toBeTrue()
        ->and(Permission::findByName(Permiso::ActuacionesVer->value))->not->toBeNull();
});

test('roles con usuarios asignados y el rol administrador no pueden eliminarse', function () {
    $administrador = User::factory()->administrador()->create();
    $role = Role::create(['name' => 'EN USO', 'guard_name' => 'web']);
    User::factory()->create()->syncRoles([$role]);
    $this->actingAs($administrador);

    Livewire::test('pages::admin.roles.index')
        ->call('confirmDelete', $role->id)
        ->assertHasErrors(['role']);

    expect(Role::findByName('EN USO'))->not->toBeNull()
        ->and(Role::findByName(RolUsuario::Administrador->value))->not->toBeNull();
});
