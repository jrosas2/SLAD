<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Permiso;
use Database\Factories\CausaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'nombre',
    'fecha_causa',
    'fecha_ingreso',
    'juzgado_id',
    'materia_id',
    'submateria_id',
    'estado_procesal_id',
    'direccion_id',
    'demandante_demandado',
    'accion_id',
    'estado_causa_id',
    'numero_causa',
    'monto_demandado',
    'observacion_importante',
    'tiene_cotizaciones',
])]
class Causa extends Model
{
    /** @use HasFactory<CausaFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'causas';

    /** @return BelongsTo<Juzgado, $this> */
    public function juzgado(): BelongsTo
    {
        return $this->belongsTo(Juzgado::class);
    }

    /** @return BelongsTo<Materia, $this> */
    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    /** @return BelongsTo<Submateria, $this> */
    public function submateria(): BelongsTo
    {
        return $this->belongsTo(Submateria::class);
    }

    /** @return BelongsTo<EstadoProcesal, $this> */
    public function estadoProcesal(): BelongsTo
    {
        return $this->belongsTo(EstadoProcesal::class);
    }

    /** @return BelongsTo<Direccion, $this> */
    public function direccion(): BelongsTo
    {
        return $this->belongsTo(Direccion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** @return BelongsTo<Accion, $this> */
    public function accion(): BelongsTo
    {
        return $this->belongsTo(Accion::class);
    }

    /** @return BelongsTo<EstadoCausa, $this> */
    public function estadoCausa(): BelongsTo
    {
        return $this->belongsTo(EstadoCausa::class);
    }

    /** @return HasMany<Actuacion, $this> */
    public function actuaciones(): HasMany
    {
        return $this->hasMany(Actuacion::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    /** @return HasMany<MovimientoFinanciero, $this> */
    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    /** @return HasMany<DocumentoCausa, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoCausa::class)->latest();
    }

    /** @return HasMany<Recordatorio, $this> */
    public function recordatorios(): HasMany
    {
        return $this->hasMany(Recordatorio::class)->orderBy('fecha_hora');
    }

    /** @param Builder<Causa> $query */
    public function scopeVisibleFor(Builder $query, User $user): Builder
    {
        if ($user->can(Permiso::CausasVerTodas->value)) {
            return $query;
        }

        return $query->where('responsable_id', $user->id);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_causa' => 'immutable_date',
            'fecha_ingreso' => 'immutable_date',
            'monto_demandado' => 'decimal:2',
            'tiene_cotizaciones' => 'boolean',
        ];
    }
}
