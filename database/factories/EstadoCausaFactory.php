<?php

namespace Database\Factories;

use App\Models\EstadoCausa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstadoCausa>
 */
class EstadoCausaFactory extends Factory
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
