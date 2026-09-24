<?php

namespace App\Models;

use Database\Factories\ImportacionErrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['importacion_id', 'fila', 'numero_causa', 'campo', 'valor_original', 'codigo_error', 'mensaje', 'datos_originales'])]
class ImportacionError extends Model
{
    /** @use HasFactory<ImportacionErrorFactory> */
    use HasFactory;

    protected $table = 'importacion_errors';

    /** @return BelongsTo<Importacion, $this> */
    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['datos_originales' => 'array'];
    }
}
