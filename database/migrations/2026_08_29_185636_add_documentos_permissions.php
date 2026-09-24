<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $permissions = ['documentos.ver', 'documentos.crear', 'documentos.eliminar'];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id', 'name');
        $rolePermissions = [
            'ADMINISTRADOR' => $permissions,
            'ABOGADO' => ['documentos.ver', 'documentos.crear'],
            'CONSULTA' => ['documentos.ver'],
        ];

        foreach ($rolePermissions as $roleName => $rolePermissionNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($rolePermissionNames as $permissionName) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$permissionName],
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissions = ['documentos.ver', 'documentos.crear', 'documentos.eliminar'];
        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
