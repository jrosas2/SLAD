<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\TipoMovimientoFinanciero;
use Database\Factories\MovimientoFinancieroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['causa_id', 'tipo', 'monto', 'fecha', 'observacion', 'created_by'])]
class MovimientoFinanciero extends Model
{
    /** @use HasFactory<MovimientoFinancieroFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'movimientos_financieros';

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoFinanciero::class,
            'monto' => 'decimal:2',
            'fecha' => 'immutable_date',
        ];
    }
}
