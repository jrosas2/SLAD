<?php

namespace Database\Factories;

use App\Models\EstadoProcesal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstadoProcesal>
 */
class EstadoProcesalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
            'activo' => true,
        ];
    }
}
