<?php

namespace App\Models;

use Database\Factories\DocumentoCausaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['causa_id', 'user_id', 'nombre_original', 'nombre_archivo', 'ruta', 'mime_type', 'tamano_original', 'tamano_almacenado', 'comprimido', 'hash_sha256', 'descripcion'])]
class DocumentoCausa extends Model
{
    /** @use HasFactory<DocumentoCausaFactory> */
    use HasFactory;

    protected $table = 'documentos_causa';

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['comprimido' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
