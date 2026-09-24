<?php

namespace App\Models;

use Database\Factories\SubmateriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['materia_id', 'nombre', 'activo'])]
class Submateria extends Model
{
    /** @use HasFactory<SubmateriaFactory> */
    use HasFactory;

    protected $table = 'submaterias';

    protected $attributes = [
        'activo' => true,
    ];

    /** @return BelongsTo<Materia, $this> */
    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    /** @return HasMany<Causa, $this> */
    public function causas(): HasMany
    {
        return $this->hasMany(Causa::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
