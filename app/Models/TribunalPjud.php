<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tribunal del catálogo oficial del Poder Judicial (Excel de códigos PJUD).
 */
#[Fillable(['codigo', 'tipo_juzgado', 'nombre', 'nombre_normalizado', 'corte_apelaciones'])]
class TribunalPjud extends Model
{
    protected $table = 'tribunales_pjud';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @return HasMany<Juzgado, $this> */
    public function juzgados(): HasMany
    {
        return $this->hasMany(Juzgado::class, 'tribunal_pjud_codigo', 'codigo');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'codigo' => 'integer',
            'tipo_juzgado' => 'integer',
        ];
    }
}
