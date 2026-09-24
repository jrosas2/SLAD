<?php

namespace Database\Seeders;

use App\Models\Materia;
use App\Models\Submateria;
use Illuminate\Database\Seeder;
use LogicException;

class SubmateriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(MateriaSeeder::class);

        /** @var array<string, list<string>> $submateriasPorMateria */
        $submateriasPorMateria = [
            'CS' => [
                'APELACION RC',
                'CASACION FONDO',
                'OTRO',
                'UNIFICACION',
            ],
            'CA' => [
                'AMPARO',
                'APELACION DEF.',
                'APELACION INC.',
                'APELACION JPL',
                'APELACION PENAL',
                'NULIDAD LAB.',
                'OTRO',
                'R. ILEGALIDAD',
                'R. PROTECCION',
                'R. HECHO',
            ],
            'CIVIL' => [
                'CTA. PUBLICA',
                'REND. CUENTA',
                'EJECUTIVA',
                'GESTION PREPARATORIA',
                'INDEMNIZACION PERJUICIOS',
                'M. PREJUDICIAL PRECAUTORIA',
                'OTRO',
                'PRESCRIPCION',
                'RES/CUMP. CONTRATO',
                'NULIDAD DERECHO PUBLICO',
                'RESTITUCION PROPIEDAD',
                'EXHORTO',
                'M. PREJUDICIAL PROBATORIA',
                'ORDINARIO',
                'HACIENDA',
                'COBRO PESO',
                'COBRANZA MUNICIPAL',
            ],
            'COBRANZA' => [
                'AFP',
                'CESANTIA',
                'SALUD',
                'OTRO',
                'CUMP. SENTENCIA',
                'TRANSACCION',
            ],
            'LABORAL' => [
                'HONORARIOS',
                'MONITORIO',
                'TUTELA',
                'INDM. PERJ',
                'DESPIDO INJUST',
                'COBRO PREST.',
                'PREJUDICIAL',
                'PRECAUTORIA',
            ],
            'JPL' => [
                'DENUNCIANTE',
            ],
            'GRT' => [
                'QUERELLANTE',
                'QUERELLADO',
            ],
            'TC' => [
                'INAPLICABILIDAD',
            ],
            'OTRO' => [
                'TRANSACCION',
            ],
        ];

        $materias = Materia::query()
            ->select(['id', 'nombre'])
            ->whereIn('nombre', array_keys($submateriasPorMateria))
            ->get()
            ->keyBy('nombre');

        foreach ($submateriasPorMateria as $nombreMateria => $nombres) {
            $materia = $materias->get($nombreMateria);

            if (! $materia instanceof Materia) {
                throw new LogicException("La materia {$nombreMateria} no existe en el catálogo de materias.");
            }

            foreach ($nombres as $nombre) {
                Submateria::query()->updateOrCreate(
                    [
                        'materia_id' => $materia->id,
                        'nombre' => $nombre,
                    ],
                    ['activo' => true],
                );
            }
        }
    }
}
