<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use Database\Factories\AuditoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'accion', 'modelo', 'modelo_id', 'causa_id', 'valores_anteriores', 'valores_nuevos', 'ip_address', 'user_agent'])]
class Auditoria extends Model
{
    /** @use HasFactory<AuditoriaFactory> */
    use HasFactory;

    protected $table = 'auditorias';

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accion' => AccionAuditoria::class,
            'valores_anteriores' => 'array',
            'valores_nuevos' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
