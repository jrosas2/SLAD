<?php

namespace App\Models;

use App\Enums\Permiso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Movimientos visibles en Monitoreo › Causas: todos con "monitoreo.ver-todas";
     * si no, sólo los de causas de las que el usuario es responsable.
     *
     * @param  Builder<PjudMovimiento>  $query
     * @return Builder<PjudMovimiento>
     */
    public function scopeVisibleFor(Builder $query, User $user): Builder
    {
        if ($user->can(Permiso::MonitoreoVerTodas->value)) {
            return $query;
        }

        return $query->whereHas('causa', fn (Builder $causa) => $causa->where('responsable_id', $user->id));
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
