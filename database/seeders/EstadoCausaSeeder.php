<?php

namespace Database\Seeders;

use App\Models\EstadoCausa;
use Illuminate\Database\Seeder;

class EstadoCausaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['VIGENTE', 'CERRADA'] as $nombre) {
            EstadoCausa::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['activo' => true],
            );
        }
    }
}
