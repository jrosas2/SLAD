<?php

namespace App\Models;

use Database\Factories\EstadoProcesalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class EstadoProcesal extends Model
{
    /** @use HasFactory<EstadoProcesalFactory> */
    use HasFactory;

    protected $table = 'estados_procesales';

    protected $attributes = [
        'activo' => true,
    ];

    /** @return HasMany<Causa, $this> */
    public function causas(): HasMany
    {
        return $this->hasMany(Causa::class);
    }

    /** @return HasMany<Actuacion, $this> */
    public function actuaciones(): HasMany
    {
        return $this->hasMany(Actuacion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
