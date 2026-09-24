<?php

namespace Database\Factories;

use App\Models\Ciudad;
use App\Models\Juzgado;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Juzgado>
 */
class JuzgadoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ciudad_id' => Ciudad::factory(),
            'nombre' => fake()->unique()->words(4, true),
            'activo' => true,
        ];
    }
}
