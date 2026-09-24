<?php

namespace Database\Seeders;

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permiso::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RolUsuario::cases() as $initialRole) {
            $role = Role::query()->firstOrCreate(
                ['name' => $initialRole->value, 'guard_name' => 'web'],
                ['description' => $initialRole->descripcion()],
            );

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(array_map(
                    fn (Permiso $permission): string => $permission->value,
                    $initialRole->permisosIniciales(),
                ));
            }
        }

        Role::findByName(RolUsuario::Administrador->value, 'web')
            ->syncPermissions(array_map(fn (Permiso $permission): string => $permission->value, Permiso::cases()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
