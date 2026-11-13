<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permite descartar falsos positivos del monitoreo PJUD. El registro no se
     * borra (soft delete): conserva su "huella" para que el escaneo no lo
     * vuelva a crear ni a notificar, y guarda quién lo descartó y por qué.
     */
    public function up(): void
    {
        foreach (['pjud_movimientos', 'pjud_programaciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->softDeletes();
                $table->foreignId('descartado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('motivo_descarte')->nullable();
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(Permiso::MonitoreoEliminar->value, 'web');
        Role::query()->where('name', RolUsuario::Administrador->value)->first()?->givePermissionTo(Permiso::MonitoreoEliminar->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['pjud_movimientos', 'pjud_programaciones'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('descartado_por');
                $table->dropColumn('motivo_descarte');
                $table->dropSoftDeletes();
            });
        }

        foreach (Role::query()->get() as $role) {
            if ($role->hasPermissionTo(Permiso::MonitoreoEliminar->value)) {
                $role->revokePermissionTo(Permiso::MonitoreoEliminar->value);
            }
        }

        Permission::query()->where('name', Permiso::MonitoreoEliminar->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
