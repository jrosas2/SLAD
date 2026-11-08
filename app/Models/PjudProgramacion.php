<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Causa de SLAD detectada en la programación de salas (tabla) de una Corte.
 */
#[Fillable([
    'causa_id',
    'tribunal_pjud_codigo',
    'tribunal_nombre',
    'tipo_tabla_codigo',
    'tipo_tabla',
    'fecha_vista',
    'sala_numero',
    'sala',
    'relator',
    'numero_orden',
    'numero_ingreso',
    'tipo_coincidencia',
    'caratulado',
    'huella',
    'detectada_en',
])]
class PjudProgramacion extends Model
{
    protected $table = 'pjud_programaciones';

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
            'fecha_vista' => 'immutable_date',
            'numero_orden' => 'integer',
            'detectada_en' => 'immutable_datetime',
            'notificado_at' => 'immutable_datetime',
        ];
    }
}
