<?php

namespace Database\Factories;

use App\Enums\AccionAuditoria;
use App\Models\Auditoria;
use App\Models\Causa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auditoria>
 */
class AuditoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'accion' => AccionAuditoria::Modificado,
            'modelo' => Causa::class,
            'modelo_id' => fake()->numberBetween(1, 100),
            'causa_id' => null,
            'valores_anteriores' => ['nombre' => 'Anterior'],
            'valores_nuevos' => ['nombre' => 'Nuevo'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
        ];
    }
}
