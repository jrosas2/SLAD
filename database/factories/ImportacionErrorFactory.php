<?php

namespace Database\Factories;

use App\Models\Importacion;
use App\Models\ImportacionError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportacionError>
 */
class ImportacionErrorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'importacion_id' => Importacion::factory(),
            'fila' => 2,
            'numero_causa' => 'C-100',
            'campo' => 'fecha_causa',
            'valor_original' => 'fecha inválida',
            'codigo_error' => 'FECHA_INVALIDA',
            'mensaje' => 'No fue posible interpretar la fecha.',
            'datos_originales' => [],
        ];
    }
}
