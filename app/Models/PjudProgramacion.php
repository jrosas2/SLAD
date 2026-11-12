<?php

namespace App\Models;

use App\Enums\Permiso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Programaciones que el usuario puede ver en el historial de salas: todas
     * con "monitoreo.ver-todas"; si no, sólo las de causas de las que es responsable.
     *
     * @param  Builder<PjudProgramacion>  $query
     * @return Builder<PjudProgramacion>
     */
    public function scopeVisibleFor(Builder $query, User $user): Builder
    {
        if ($user->can(Permiso::MonitoreoVerTodas->value)) {
            return $query;
        }

        return $query->whereHas('causa', fn (Builder $causa) => $causa->where('responsable_id', $user->id));
    }

    /**
     * Programaciones reemplazadas: la misma causa apareció después en la tabla
     * de la misma Corte con otra fecha o sala, antes de que llegara su fecha de
     * vista (si apareció después de la vista, es una vista nueva, no un cambio).
     *
     * @param  Builder<PjudProgramacion>  $query
     * @return Builder<PjudProgramacion>
     */
    public function scopeReprogramadas(Builder $query): Builder
    {
        $tabla = $query->getModel()->getTable();

        return $query->whereExists(fn ($posterior) => $posterior
            ->selectRaw('1')
            ->from($tabla.' as posterior')
            ->whereColumn('posterior.causa_id', $tabla.'.causa_id')
            ->whereColumn('posterior.tribunal_pjud_codigo', $tabla.'.tribunal_pjud_codigo')
            ->whereColumn('posterior.tipo_tabla_codigo', $tabla.'.tipo_tabla_codigo')
            ->whereColumn('posterior.id', '!=', $tabla.'.id')
            ->whereColumn('posterior.detectada_en', '>', $tabla.'.detectada_en')
            ->whereRaw('DATE(posterior.detectada_en) <= DATE('.$tabla.'.fecha_vista)')
            ->where(fn ($cambio) => $cambio
                ->whereColumn('posterior.fecha_vista', '!=', $tabla.'.fecha_vista')
                ->orWhereColumn('posterior.sala_numero', '!=', $tabla.'.sala_numero')));
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
