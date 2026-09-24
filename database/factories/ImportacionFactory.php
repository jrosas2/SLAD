<?php

namespace Database\Factories;

use App\Enums\EstadoImportacion;
use App\Models\Importacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Importacion>
 */
class ImportacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'archivo' => 'historico.xlsx',
            'ruta_archivo' => 'importaciones/historico.xlsx',
            'hash_archivo' => hash('sha256', fake()->uuid()),
            'user_id' => User::factory(),
            'estado' => EstadoImportacion::Analizada,
            'total_filas' => 1,
        ];
    }
}
