<?php

namespace App\Models;

use Database\Factories\MateriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class Materia extends Model
{
    /** @use HasFactory<MateriaFactory> */
    use HasFactory;

    protected $table = 'materias';

    protected $attributes = [
        'activo' => true,
    ];

    /** @return HasMany<Submateria, $this> */
    public function submaterias(): HasMany
    {
        return $this->hasMany(Submateria::class);
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
