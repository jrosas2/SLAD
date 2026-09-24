<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            'dashboard.ver',
            'causas.ver', 'causas.crear', 'causas.editar', 'causas.eliminar', 'causas.asignar-responsable',
            'actuaciones.ver', 'actuaciones.crear', 'actuaciones.editar', 'actuaciones.eliminar',
            'movimientos.ver', 'movimientos.crear', 'movimientos.editar', 'movimientos.eliminar',
            'catalogos.ver', 'catalogos.crear', 'catalogos.editar', 'catalogos.desactivar',
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar', 'usuarios.asignar-roles',
            'roles.ver', 'roles.crear', 'roles.editar', 'roles.eliminar', 'roles.asignar-permisos',
            'importaciones.ver', 'importaciones.ejecutar',
        ];
        $initialRoles = [
            'ADMINISTRADOR' => $permissions,
            'ABOGADO' => [
                'dashboard.ver', 'causas.ver', 'causas.crear', 'causas.editar',
                'actuaciones.ver', 'actuaciones.crear', 'actuaciones.editar',
                'movimientos.ver', 'movimientos.crear', 'movimientos.editar', 'catalogos.ver',
            ],
            'CONSULTA' => [
                'dashboard.ver', 'causas.ver', 'actuaciones.ver', 'movimientos.ver', 'catalogos.ver',
            ],
        ];
        $descriptions = [
            'ADMINISTRADOR' => 'Administración completa del sistema.',
            'ABOGADO' => 'Gestión jurídica y operativa de causas.',
            'CONSULTA' => 'Consulta de información sin facultades de modificación.',
        ];
        $now = now();

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $legacyRoles = DB::table('users')->distinct()->pluck('rol')->filter()->all();

        foreach (array_unique([...array_keys($initialRoles), ...$legacyRoles]) as $roleName) {
            DB::table('roles')->insertOrIgnore([
                'name' => $roleName,
                'description' => $descriptions[$roleName] ?? null,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($initialRoles as $roleName => $rolePermissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            $permissionIds = DB::table('permissions')->whereIn('name', $rolePermissions)->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        DB::table('users')->select(['id', 'rol'])->orderBy('id')->each(function (object $user): void {
            $roleId = DB::table('roles')->where('name', $user->rol)->where('guard_name', 'web')->value('id');

            if ($roleId !== null) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->select('id')->orderBy('id')->each(function (object $user): void {
            $roleName = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('model_has_roles.model_id', $user->id)
                ->value('roles.name');

            if ($roleName !== null) {
                DB::table('users')->where('id', $user->id)->update(['rol' => $roleName]);
            }
        });
    }
};
