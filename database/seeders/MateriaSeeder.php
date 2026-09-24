<?php

namespace Database\Seeders;

use App\Models\Materia;
use Illuminate\Database\Seeder;

class MateriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $materias = [
            'CS',
            'CA',
            'JPL',
            'CIVIL',
            'LABORAL',
            'GRT',
            'TA',
            'TCP',
            'TC',
            'COBRANZA',
            'OTRO',
        ];

        foreach ($materias as $nombre) {
            Materia::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['activo' => true],
            );
        }
    }
}
