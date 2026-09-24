<?php

namespace App\Actions\MovimientosFinancieros;

use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\MovimientoFinanciero;
use App\Models\User;

class GuardarMovimientoFinanciero
{
    /**
     * @param  array{tipo: TipoMovimientoFinanciero, fecha: string, monto: int, observacion: string|null}  $attributes
     */
    public function handle(Causa $causa, array $attributes, User $usuario, ?MovimientoFinanciero $movimiento = null): MovimientoFinanciero
    {
        if ($movimiento === null) {
            return $causa->movimientosFinancieros()->create([
                ...$attributes,
                'created_by' => $usuario->id,
            ]);
        }

        $movimientoGuardado = MovimientoFinanciero::query()
            ->whereBelongsTo($causa)
            ->findOrFail($movimiento->id);

        $movimientoGuardado->update($attributes);

        return $movimientoGuardado->refresh();
    }
}
