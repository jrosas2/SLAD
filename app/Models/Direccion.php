<?php

namespace App\Models;

use Database\Factories\DireccionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class Direccion extends Model
{
    /** @use HasFactory<DireccionFactory> */
    use HasFactory;

    protected $table = 'direcciones';

    protected $attributes = [
        'activo' => true,
    ];

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
