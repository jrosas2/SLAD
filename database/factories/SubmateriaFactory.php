<?php

namespace Database\Factories;

use App\Models\Materia;
use App\Models\Submateria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submateria>
 */
class SubmateriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'materia_id' => Materia::factory(),
            'nombre' => fake()->unique()->words(3, true),
            'activo' => true,
        ];
    }
}
