<?php

namespace App\Models;

use Database\Factories\CiudadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class Ciudad extends Model
{
    /** @use HasFactory<CiudadFactory> */
    use HasFactory;

    protected $table = 'ciudades';

    protected $attributes = [
        'activo' => true,
    ];

    /** @return HasMany<Juzgado, $this> */
    public function juzgados(): HasMany
    {
        return $this->hasMany(Juzgado::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
