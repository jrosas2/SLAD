<?php

namespace App\Models;

use Database\Factories\JuzgadoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ciudad_id', 'nombre', 'tribunal_pjud_codigo', 'activo'])]
class Juzgado extends Model
{
    /** @use HasFactory<JuzgadoFactory> */
    use HasFactory;

    protected $table = 'juzgados';

    protected $attributes = [
        'activo' => true,
    ];

    /** @return BelongsTo<Ciudad, $this> */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }

    /** @return BelongsTo<TribunalPjud, $this> */
    public function tribunalPjud(): BelongsTo
    {
        return $this->belongsTo(TribunalPjud::class, 'tribunal_pjud_codigo', 'codigo');
    }

    /** @return HasMany<Causa, $this> */
    public function causas(): HasMany
    {
        return $this->hasMany(Causa::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'tribunal_pjud_codigo' => 'integer',
        ];
    }
}
