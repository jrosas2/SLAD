<?php

namespace Database\Seeders;

use App\Models\Ciudad;
use App\Models\Juzgado;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;
use SplFileObject;

class JuzgadoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(CiudadSeeder::class);

        $ciudades = Ciudad::query()
            ->select(['id', 'nombre'])
            ->get()
            ->keyBy('nombre');

        $archivo = new SplFileObject(database_path('seeders/data/juzgados_por_ciudad_chile.csv'));
        $archivo->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $archivo->setCsvControl(',', '"', '');

        foreach ($archivo as $indice => $fila) {
            if ($indice === 0 || ! is_array($fila) || count($fila) < 2) {
                continue;
            }

            $nombreCiudad = Str::upper(Str::squish((string) $fila[0]));
            $nombreJuzgado = Str::squish((string) $fila[1]);
            $ciudad = $ciudades->get($nombreCiudad);

            if (! $ciudad instanceof Ciudad) {
                throw new LogicException("La ciudad {$nombreCiudad} no existe en el catálogo de ciudades.");
            }

            Juzgado::query()->updateOrCreate(
                [
                    'ciudad_id' => $ciudad->id,
                    'nombre' => $nombreJuzgado,
                ],
                ['activo' => true],
            );
        }
    }
}
