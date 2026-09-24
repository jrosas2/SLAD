<?php

namespace App\Enums;

enum EstadoRecordatorio: string
{
    case Pendiente = 'PENDIENTE';
    case Completado = 'COMPLETADO';
    case Cancelado = 'CANCELADO';
}
