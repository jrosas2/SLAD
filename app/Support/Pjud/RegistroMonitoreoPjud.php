<?php

namespace App\Support\Pjud;

use Illuminate\Support\Facades\Cache;

/**
 * Contadores del día del monitoreo PJUD (revisiones, consultas correctas y con
 * error), para el aviso de status de cierre. Se guardan en la caché de Laravel
 * por 3 días; si la caché se limpia, el resumen muestra cero en esos contadores.
 */
class RegistroMonitoreoPjud
{
    public static function sumar(string $contador, int $cantidad = 1): void
    {
        $clave = self::clave($contador);
        Cache::add($clave, 0, now()->addDays(3));
        Cache::increment($clave, $cantidad);
    }

    public static function anotarError(int $tribunalCodigo): void
    {
        $clave = self::clave('tribunales_error');
        $codigos = (array) Cache::get($clave, []);
        $codigos[] = $tribunalCodigo;
        Cache::put($clave, array_values(array_unique($codigos)), now()->addDays(3));
    }

    /**
     * @return array{revisiones: int, consultas_ok: int, consultas_error: int, tribunales_error: list<int>}
     */
    public static function delDia(?string $fecha = null): array
    {
        return [
            'revisiones' => (int) Cache::get(self::clave('revisiones', $fecha), 0),
            'consultas_ok' => (int) Cache::get(self::clave('consultas_ok', $fecha), 0),
            'consultas_error' => (int) Cache::get(self::clave('consultas_error', $fecha), 0),
            'tribunales_error' => array_values(array_map('intval', (array) Cache::get(self::clave('tribunales_error', $fecha), []))),
        ];
    }

    private static function clave(string $contador, ?string $fecha = null): string
    {
        return 'pjud-monitoreo:'.($fecha ?? now()->toDateString()).':'.$contador;
    }
}
