<?php

namespace App\Models;

use App\Enums\EstadoImportacion;
use Database\Factories\ImportacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['archivo', 'ruta_archivo', 'hash_archivo', 'user_id', 'estado', 'total_filas', 'filas_importadas', 'filas_omitidas', 'filas_error', 'fecha_inicio', 'fecha_fin', 'resumen'])]
class Importacion extends Model
{
    /** @use HasFactory<ImportacionFactory> */
    use HasFactory;

    protected $table = 'importaciones';

    protected $attributes = [
        'estado' => EstadoImportacion::Pendiente->value,
        'total_filas' => 0,
        'filas_importadas' => 0,
        'filas_omitidas' => 0,
        'filas_error' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<ImportacionError, $this> */
    public function errores(): HasMany
    {
        return $this->hasMany(ImportacionError::class)->orderBy('fila')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'estado' => EstadoImportacion::class,
            'fecha_inicio' => 'immutable_datetime',
            'fecha_fin' => 'immutable_datetime',
            'resumen' => 'array',
        ];
    }
}
