<?php

namespace App\Actions\Actuaciones;

use App\Models\Causa;

class SincronizarEstadoProcesalCausa
{
    public function handle(Causa $causa): void
    {
        $estadoProcesalId = $causa->actuaciones()
            ->reorder()
            ->whereNotNull('estado_procesal_id')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->value('estado_procesal_id');

        $causa->update(['estado_procesal_id' => $estadoProcesalId]);
    }
}
