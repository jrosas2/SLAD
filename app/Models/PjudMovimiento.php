<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de una causa de SLAD detectado en el Estado Diario del PJUD.
 */
#[Fillable([
    'causa_id',
    'tribunal_pjud_codigo',
    'fecha_estado_diario',
    'numero_ingreso',
    'tipo_coincidencia',
    'tribunal_nombre',
    'caratulado',
    'tramite',
    'detalle',
    'huella',
])]
class PjudMovimiento extends Model
{
    protected $table = 'pjud_movimientos';

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class)->withTrashed();
    }

    /** @return BelongsTo<TribunalPjud, $this> */
    public function tribunalPjud(): BelongsTo
    {
        return $this->belongsTo(TribunalPjud::class, 'tribunal_pjud_codigo', 'codigo');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_estado_diario' => 'immutable_date',
            'notificado_at' => 'immutable_datetime',
        ];
    }
}
