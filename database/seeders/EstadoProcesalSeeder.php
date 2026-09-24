<?php

namespace Database\Seeders;

use App\Models\EstadoProcesal;
use Illuminate\Database\Seeder;

class EstadoProcesalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $estados = [
            'DISCUSIÓN',
            'CONCILIACIÓN',
            'PROBATORIO',
            'SENTENCIA',
            'RECURSOS',
            'EJECUCIÓN',
            'INCIDENTE',
            'ARCHIVO',
            'LIQUIDACIÓN',
            'PAGADO',
            'CONTESTACIÓN',
        ];

        foreach ($estados as $nombre) {
            EstadoProcesal::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['activo' => true],
            );
        }
    }
}
