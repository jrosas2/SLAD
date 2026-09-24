<?php

namespace Database\Factories;

use App\Models\Causa;
use App\Models\Materia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Causa>
 */
class CausaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->company().' con '.fake()->company(),
            'fecha_causa' => fake()->optional()->dateTimeBetween('-5 years'),
            'fecha_ingreso' => fake()->optional()->dateTimeBetween('-5 years'),
            'juzgado_id' => null,
            'materia_id' => Materia::factory(),
            'submateria_id' => null,
            'estado_procesal_id' => null,
            'direccion_id' => null,
            'demandante_demandado' => fake()->optional()->company(),
            'responsable_id' => null,
            'accion_id' => null,
            'estado_causa_id' => null,
            'numero_causa' => fake()->optional()->bothify('C-####-??'),
            'monto_demandado' => fake()->optional()->randomFloat(2, 10000, 500000000),
            'observacion_importante' => fake()->optional()->sentence(),
            'tiene_cotizaciones' => fake()->optional()->boolean(),
        ];
    }
}
