<?php

namespace App\Models;

use App\Enums\EstadoRecordatorio;
use Database\Factories\RecordatorioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'causa_id', 'user_id', 'created_by', 'titulo', 'descripcion', 'fecha_hora',
    'recordar_minutos_antes', 'notificar_en', 'estado', 'notificado_at',
])]
class Recordatorio extends Model
{
    /** @use HasFactory<RecordatorioFactory> */
    use HasFactory;

    protected $attributes = [
        'estado' => EstadoRecordatorio::Pendiente->value,
    ];

    /** @return BelongsTo<Causa, $this> */
    public function causa(): BelongsTo
    {
        return $this->belongsTo(Causa::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function estaVencido(): bool
    {
        return $this->estado === EstadoRecordatorio::Pendiente && $this->fecha_hora->isPast();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_hora' => 'immutable_datetime',
            'notificar_en' => 'immutable_datetime',
            'notificado_at' => 'immutable_datetime',
            'estado' => EstadoRecordatorio::class,
        ];
    }
}
