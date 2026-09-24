<?php

namespace Database\Seeders;

use App\Models\Direccion;
use Illuminate\Database\Seeder;

class DireccionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $direcciones = [
            'SEGURIDAD PUBLICA',
            'DES. COMUNITARIO',
            'PLANIFICACION',
            'JURIDICA',
            'SALUD',
            'EDUCACION',
            'ADM Y FINANZAS',
            'GESTION PERSONAS',
            'M. AMB; ASEO ORNATO',
            'OPERACIONES',
            'CONTROL',
            'TURISMO',
            'OBRAS MUNICIPALES',
            'TRANSITO',
            'DES. RURAL',
            'SECRETARIA MUNIC.',
            'PERSONAS MAYORES',
            'PRESUPUESTOS; LICIT',
            'CEMENTERIO',
            'JPL',
            'RENTAS Y PATENTES',
            'DEPORTES',
            'PARQUE CAUTIN',
            'UDEL',
            'PERSONALIDAD JURIDICA',
            'PERM. CIRCULACION',
        ];

        foreach ($direcciones as $nombre) {
            Direccion::query()->updateOrCreate(
                ['nombre' => $nombre],
                ['activo' => true],
            );
        }
    }
}
