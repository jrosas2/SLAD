<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            Permiso::RecordatoriosVer,
            Permiso::RecordatoriosCrear,
            Permiso::RecordatoriosEditar,
            Permiso::RecordatoriosCancelar,
            Permiso::RecordatoriosAsignar,
        ] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $abogado = Role::query()->where('name', RolUsuario::Abogado->value)->first();
        $abogado?->givePermissionTo([
            Permiso::RecordatoriosVer->value,
            Permiso::RecordatoriosCrear->value,
            Permiso::RecordatoriosEditar->value,
            Permiso::RecordatoriosCancelar->value,
        ]);

        Role::query()->where('name', RolUsuario::Consulta->value)->first()?->givePermissionTo(Permiso::RecordatoriosVer->value);
        Role::query()->where('name', RolUsuario::Administrador->value)->first()?->givePermissionTo(array_map(
            fn (Permiso $permission): string => $permission->value,
            Permiso::cases(),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionNames = [
            Permiso::RecordatoriosVer->value,
            Permiso::RecordatoriosCrear->value,
            Permiso::RecordatoriosEditar->value,
            Permiso::RecordatoriosCancelar->value,
            Permiso::RecordatoriosAsignar->value,
        ];

        foreach (Role::query()->get() as $role) {
            $role->revokePermissionTo(array_values(array_intersect($role->permissions->pluck('name')->all(), $permissionNames)));
        }

        Permission::query()->whereIn('name', $permissionNames)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
