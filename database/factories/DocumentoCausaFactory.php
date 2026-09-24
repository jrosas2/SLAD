<?php

namespace Database\Factories;

use App\Models\Causa;
use App\Models\DocumentoCausa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentoCausa>
 */
class DocumentoCausaFactory extends Factory
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
            'user_id' => User::factory(),
            'nombre_original' => 'documento.pdf',
            'nombre_archivo' => fake()->uuid().'.pdf',
            'ruta' => 'causas/'.fake()->numberBetween(1, 1000).'/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'tamano_original' => 2048,
            'tamano_almacenado' => 2048,
            'comprimido' => false,
            'hash_sha256' => hash('sha256', fake()->uuid()),
            'descripcion' => fake()->optional()->sentence(),
        ];
    }
}
