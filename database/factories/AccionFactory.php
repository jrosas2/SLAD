<?php

namespace Database\Factories;

use App\Models\Accion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accion>
 */
class AccionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'activo' => true,
        ];
    }
}
