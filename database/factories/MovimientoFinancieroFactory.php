<?php

namespace Database\Factories;

use App\Enums\TipoMovimientoFinanciero;
use App\Models\Causa;
use App\Models\MovimientoFinanciero;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MovimientoFinanciero>
 */
class MovimientoFinancieroFactory extends Factory
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
            'tipo' => fake()->randomElement(TipoMovimientoFinanciero::cases()),
            'monto' => fake()->numberBetween(1000, 10000000),
            'fecha' => fake()->date(),
            'observacion' => fake()->optional()->sentence(),
            'created_by' => null,
        ];
    }
}
