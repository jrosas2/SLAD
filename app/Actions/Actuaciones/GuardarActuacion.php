<?php

namespace App\Actions\Actuaciones;

use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GuardarActuacion
{
    public function __construct(private SincronizarEstadoProcesalCausa $sincronizarEstado) {}

    /**
     * @param  array{fecha: string, estado_procesal_id: int|null, descripcion: string}  $attributes
     */
    public function handle(Causa $causa, array $attributes, User $usuario, ?Actuacion $actuacion = null): Actuacion
    {
        return DB::transaction(function () use ($causa, $attributes, $usuario, $actuacion): Actuacion {
            $causaBloqueada = Causa::query()->lockForUpdate()->findOrFail($causa->id);

            if ($actuacion === null) {
                $actuacionGuardada = $causaBloqueada->actuaciones()->create([
                    ...$attributes,
                    'created_by' => $usuario->id,
                ]);
                $debeSincronizar = $attributes['estado_procesal_id'] !== null;
            } else {
                $actuacionGuardada = Actuacion::query()
                    ->whereBelongsTo($causaBloqueada)
                    ->lockForUpdate()
                    ->findOrFail($actuacion->id);
                $estadoProcesalAnterior = $actuacionGuardada->estado_procesal_id;
                $actuacionGuardada->update($attributes);
                $debeSincronizar = $estadoProcesalAnterior !== null || $attributes['estado_procesal_id'] !== null;
            }

            if ($debeSincronizar) {
                $this->sincronizarEstado->handle($causaBloqueada);
            }

            return $actuacionGuardada->refresh();
        });
    }
}
