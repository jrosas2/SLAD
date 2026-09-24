<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\ActuacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['causa_id', 'fecha', 'estado_procesal_id', 'descripcion', 'created_by'])]
class Actuacion extends Model
{
    /** @use HasFactory<ActuacionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'actuaciones';

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class);
    }

    /** @return BelongsTo<EstadoProcesal, $this> */
    public function estadoProcesal(): BelongsTo
    {
        return $this->belongsTo(EstadoProcesal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'immutable_date'];
    }
}
