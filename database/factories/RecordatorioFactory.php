<?php

namespace Database\Factories;

use App\Enums\EstadoRecordatorio;
use App\Models\Causa;
use App\Models\Recordatorio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recordatorio>
 */
class RecordatorioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'causa_id' => Causa::factory(),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'titulo' => fake()->sentence(3),
            'descripcion' => fake()->paragraph(),
            'fecha_hora' => now()->addDay(),
            'recordar_minutos_antes' => 60,
            'notificar_en' => now()->addDay()->subHour(),
            'estado' => EstadoRecordatorio::Pendiente,
            'notificado_at' => null,
        ];
    }
}
