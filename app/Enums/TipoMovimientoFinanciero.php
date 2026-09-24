<?php

namespace App\Enums;

enum TipoMovimientoFinanciero: string
{
    case Ingreso = 'INGRESO';
    case Egreso = 'EGRESO';
}
