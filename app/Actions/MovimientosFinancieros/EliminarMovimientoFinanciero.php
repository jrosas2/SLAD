<?php

namespace App\Actions\MovimientosFinancieros;

use App\Models\Causa;
use App\Models\MovimientoFinanciero;

class EliminarMovimientoFinanciero
{
    public function handle(Causa $causa, MovimientoFinanciero $movimiento): void
    {
        MovimientoFinanciero::query()
            ->whereBelongsTo($causa)
            ->findOrFail($movimiento->id)
            ->delete();
    }
}
