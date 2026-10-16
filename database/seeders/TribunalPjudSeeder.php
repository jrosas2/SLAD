<?php

namespace Database\Seeders;

use App\Models\TribunalPjud;
use App\Support\Pjud\NormalizadorPjud;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Carga el catálogo oficial de tribunales del PJUD desde
 * database/seeders/data/tribunales_pjud.csv (generado del Excel de códigos).
 * Es idempotente: se puede ejecutar las veces que se necesite.
 */
class TribunalPjudSeeder extends Seeder
{
    public function run(): void
    {
        $archivo = new SplFileObject(database_path('seeders/data/tribunales_pjud.csv'));
        $archivo->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $archivo->setCsvControl(',', '"', '');

        $equivalencias = (array) config('pjud.equivalencias_nombre', []);
        $ahora = now();
        $filas = [];

        foreach ($archivo as $indice => $fila) {
            if ($indice === 0 || ! is_array($fila) || count($fila) < 3 || ! is_numeric($fila[0])) {
                continue;
            }

            $nombre = Str::squish((string) $fila[2]);

            $filas[] = [
                'codigo' => (int) $fila[0],
                'tipo_juzgado' => (int) $fila[1],
                'nombre' => $nombre,
                'nombre_normalizado' => NormalizadorPjud::nombreTribunal($nombre, $equivalencias),
                'corte_apelaciones' => filled($fila[3] ?? null) ? Str::squish((string) $fila[3]) : null,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 200) as $lote) {
            TribunalPjud::query()->upsert(
                $lote,
                ['codigo'],
                ['tipo_juzgado', 'nombre', 'nombre_normalizado', 'corte_apelaciones', 'updated_at'],
            );
        }
    }
}
