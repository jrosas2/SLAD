<?php

namespace App\Enums;

enum RolUsuario: string
{
    case Administrador = 'ADMINISTRADOR';
    case Abogado = 'ABOGADO';
    case Consulta = 'CONSULTA';

    public function descripcion(): string
    {
        return match ($this) {
            self::Administrador => 'Administración completa del sistema.',
            self::Abogado => 'Gestión jurídica y operativa de causas.',
            self::Consulta => 'Consulta de información sin facultades de modificación.',
        };
    }

    /** @return list<Permiso> */
    public function permisosIniciales(): array
    {
        return match ($this) {
            self::Administrador => Permiso::cases(),
            self::Abogado => [
                Permiso::DashboardVer,
                Permiso::CausasVer,
                Permiso::CausasCrear,
                Permiso::CausasEditar,
                Permiso::ActuacionesVer,
                Permiso::ActuacionesCrear,
                Permiso::ActuacionesEditar,
                Permiso::MovimientosVer,
                Permiso::MovimientosCrear,
                Permiso::MovimientosEditar,
                Permiso::DocumentosVer,
                Permiso::DocumentosCrear,
                Permiso::RecordatoriosVer,
                Permiso::RecordatoriosCrear,
                Permiso::RecordatoriosEditar,
                Permiso::RecordatoriosCancelar,
                Permiso::CatalogosVer,
            ],
            self::Consulta => [
                Permiso::DashboardVer,
                Permiso::CausasVer,
                Permiso::ActuacionesVer,
                Permiso::MovimientosVer,
                Permiso::DocumentosVer,
                Permiso::RecordatoriosVer,
                Permiso::CatalogosVer,
            ],
        };
    }
}
