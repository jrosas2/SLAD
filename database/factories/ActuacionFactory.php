<?php

namespace Database\Factories;

use App\Models\Actuacion;
use App\Models\Causa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Actuacion>
 */
class ActuacionFactory extends Factory
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
            'fecha' => fake()->date(),
            'estado_procesal_id' => null,
            'descripcion' => fake()->paragraph(),
            'created_by' => null,
        ];
    }
}
