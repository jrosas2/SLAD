<?php

namespace App\Support\Pjud;

use Illuminate\Support\Facades\Cache;

/**
 * Contadores del día del monitoreo PJUD (revisiones, consultas correctas y con
 * error), para el aviso de status de cierre. Se guardan en la caché de Laravel
 * por 3 días; si la caché se limpia, el resumen muestra cero en esos contadores.
 *
 * El Estado Diario y la programación de salas llevan contadores separados
 * (parámetro $monitoreo).
 */
class RegistroMonitoreoPjud
{
    public const ESTADO_DIARIO = 'pjud-monitoreo';

    public const PROGRAMACION = 'pjud-programacion';

    public static function sumar(string $contador, int $cantidad = 1, string $monitoreo = self::ESTADO_DIARIO): void
    {
        $clave = self::clave($contador, null, $monitoreo);
        Cache::add($clave, 0, now()->addDays(3));
        Cache::increment($clave, $cantidad);
    }

    public static function anotarError(int $tribunalCodigo, string $monitoreo = self::ESTADO_DIARIO): void
    {
        $clave = self::clave('tribunales_error', null, $monitoreo);
        $codigos = (array) Cache::get($clave, []);
        $codigos[] = $tribunalCodigo;
        Cache::put($clave, array_values(array_unique($codigos)), now()->addDays(3));
    }

    /**
     * @return array{revisiones: int, consultas_ok: int, consultas_error: int, tribunales_error: list<int>}
     */
    public static function delDia(?string $fecha = null, string $monitoreo = self::ESTADO_DIARIO): array
    {
        return [
            'revisiones' => (int) Cache::get(self::clave('revisiones', $fecha, $monitoreo), 0),
            'consultas_ok' => (int) Cache::get(self::clave('consultas_ok', $fecha, $monitoreo), 0),
            'consultas_error' => (int) Cache::get(self::clave('consultas_error', $fecha, $monitoreo), 0),
            'tribunales_error' => array_values(array_map('intval', (array) Cache::get(self::clave('tribunales_error', $fecha, $monitoreo), []))),
        ];
    }

    private static function clave(string $contador, ?string $fecha = null, string $monitoreo = self::ESTADO_DIARIO): string
    {
        return $monitoreo.':'.($fecha ?? now()->toDateString()).':'.$contador;
    }
}
