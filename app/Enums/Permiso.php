<?php

namespace App\Enums;

enum Permiso: string
{
    case DashboardVer = 'dashboard.ver';
    case CausasVer = 'causas.ver';
    case CausasVerTodas = 'causas.ver-todas';
    case CausasCrear = 'causas.crear';
    case CausasEditar = 'causas.editar';
    case CausasEliminar = 'causas.eliminar';
    case CausasAsignarResponsable = 'causas.asignar-responsable';
    case ActuacionesVer = 'actuaciones.ver';
    case ActuacionesCrear = 'actuaciones.crear';
    case ActuacionesEditar = 'actuaciones.editar';
    case ActuacionesEliminar = 'actuaciones.eliminar';
    case MovimientosVer = 'movimientos.ver';
    case MovimientosCrear = 'movimientos.crear';
    case MovimientosEditar = 'movimientos.editar';
    case MovimientosEliminar = 'movimientos.eliminar';
    case DocumentosVer = 'documentos.ver';
    case DocumentosCrear = 'documentos.crear';
    case DocumentosEliminar = 'documentos.eliminar';
    case CatalogosVer = 'catalogos.ver';
    case CatalogosCrear = 'catalogos.crear';
    case CatalogosEditar = 'catalogos.editar';
    case CatalogosDesactivar = 'catalogos.desactivar';
    case UsuariosVer = 'usuarios.ver';
    case UsuariosCrear = 'usuarios.crear';
    case UsuariosEditar = 'usuarios.editar';
    case UsuariosDesactivar = 'usuarios.desactivar';
    case UsuariosAsignarRoles = 'usuarios.asignar-roles';
    case RolesVer = 'roles.ver';
    case RolesCrear = 'roles.crear';
    case RolesEditar = 'roles.editar';
    case RolesEliminar = 'roles.eliminar';
    case RolesAsignarPermisos = 'roles.asignar-permisos';
    case ImportacionesVer = 'importaciones.ver';
    case ImportacionesEjecutar = 'importaciones.ejecutar';
    case AuditoriaVer = 'auditoria.ver';
    case RecordatoriosVer = 'recordatorios.ver';
    case RecordatoriosCrear = 'recordatorios.crear';
    case RecordatoriosEditar = 'recordatorios.editar';
    case RecordatoriosCancelar = 'recordatorios.cancelar';
    case RecordatoriosAsignar = 'recordatorios.asignar';

    public function modulo(): string
    {
        return match ($this) {
            self::DashboardVer => 'Dashboard',
            self::CausasVer, self::CausasVerTodas, self::CausasCrear, self::CausasEditar, self::CausasEliminar, self::CausasAsignarResponsable => 'Causas',
            self::ActuacionesVer, self::ActuacionesCrear, self::ActuacionesEditar, self::ActuacionesEliminar => 'Actuaciones',
            self::MovimientosVer, self::MovimientosCrear, self::MovimientosEditar, self::MovimientosEliminar => 'Movimientos financieros',
            self::DocumentosVer, self::DocumentosCrear, self::DocumentosEliminar => 'Documentos',
            self::CatalogosVer, self::CatalogosCrear, self::CatalogosEditar, self::CatalogosDesactivar => 'Catálogos',
            self::UsuariosVer, self::UsuariosCrear, self::UsuariosEditar, self::UsuariosDesactivar, self::UsuariosAsignarRoles => 'Usuarios',
            self::RolesVer, self::RolesCrear, self::RolesEditar, self::RolesEliminar, self::RolesAsignarPermisos => 'Roles y permisos',
            self::ImportacionesVer, self::ImportacionesEjecutar => 'Importaciones',
            self::AuditoriaVer => 'Auditoría',
            self::RecordatoriosVer, self::RecordatoriosCrear, self::RecordatoriosEditar, self::RecordatoriosCancelar, self::RecordatoriosAsignar => 'Recordatorios',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::DashboardVer => 'Ver dashboard',
            self::CausasVer => 'Ver causas',
            self::CausasVerTodas => 'Ver todas las causas',
            self::CausasCrear => 'Crear causas',
            self::CausasEditar => 'Editar causas',
            self::CausasEliminar => 'Eliminar causas',
            self::CausasAsignarResponsable => 'Asignar responsable',
            self::ActuacionesVer => 'Ver actuaciones',
            self::ActuacionesCrear => 'Crear actuaciones',
            self::ActuacionesEditar => 'Editar actuaciones',
            self::ActuacionesEliminar => 'Eliminar actuaciones',
            self::MovimientosVer => 'Ver movimientos',
            self::MovimientosCrear => 'Crear movimientos',
            self::MovimientosEditar => 'Editar movimientos',
            self::MovimientosEliminar => 'Eliminar movimientos',
            self::DocumentosVer => 'Ver documentos',
            self::DocumentosCrear => 'Adjuntar documentos',
            self::DocumentosEliminar => 'Eliminar documentos',
            self::CatalogosVer => 'Ver catálogos',
            self::CatalogosCrear => 'Crear registros',
            self::CatalogosEditar => 'Editar registros',
            self::CatalogosDesactivar => 'Desactivar registros',
            self::UsuariosVer => 'Ver usuarios',
            self::UsuariosCrear => 'Crear usuarios',
            self::UsuariosEditar => 'Editar usuarios',
            self::UsuariosDesactivar => 'Desactivar usuarios',
            self::UsuariosAsignarRoles => 'Asignar roles',
            self::RolesVer => 'Ver roles',
            self::RolesCrear => 'Crear roles',
            self::RolesEditar => 'Editar roles',
            self::RolesEliminar => 'Eliminar roles',
            self::RolesAsignarPermisos => 'Asignar permisos',
            self::ImportacionesVer => 'Ver importaciones',
            self::ImportacionesEjecutar => 'Ejecutar importaciones',
            self::AuditoriaVer => 'Ver bitácora de auditoría',
            self::RecordatoriosVer => 'Ver recordatorios',
            self::RecordatoriosCrear => 'Crear recordatorios',
            self::RecordatoriosEditar => 'Editar recordatorios',
            self::RecordatoriosCancelar => 'Cancelar recordatorios',
            self::RecordatoriosAsignar => 'Asignar recordatorios',
        };
    }

    /** @return array<string, list<self>> */
    public static function agrupados(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->modulo()][] = $permission;
        }

        return $groups;
    }
}
