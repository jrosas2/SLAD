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
     * Menú Monitoreo PJUD (Causas: movimientos del Estado Diario; Salas:
     * historial de salas): "monitoreo.ver" da acceso al menú y
     * "monitoreo.ver-todas" permite ver todas las causas (sin él, cada
     * usuario ve sólo las causas de las que es responsable).
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([Permiso::MonitoreoVer, Permiso::MonitoreoVerTodas] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        Role::query()->where('name', RolUsuario::Administrador->value)->first()
            ?->givePermissionTo([Permiso::MonitoreoVer->value, Permiso::MonitoreoVerTodas->value]);

        foreach ([RolUsuario::Abogado, RolUsuario::Consulta] as $rol) {
            Role::query()->where('name', $rol->value)->first()?->givePermissionTo(Permiso::MonitoreoVer->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionNames = [Permiso::MonitoreoVer->value, Permiso::MonitoreoVerTodas->value];

        foreach (Role::query()->get() as $role) {
            $role->revokePermissionTo(array_values(array_intersect($role->permissions->pluck('name')->all(), $permissionNames)));
        }

        Permission::query()->whereIn('name', $permissionNames)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
