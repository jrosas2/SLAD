<?php

namespace App\Actions\Actuaciones;

use App\Models\Actuacion;
use App\Models\Causa;
use Illuminate\Support\Facades\DB;

class EliminarActuacion
{
    public function __construct(private SincronizarEstadoProcesalCausa $sincronizarEstado) {}

    public function handle(Causa $causa, Actuacion $actuacion): void
    {
        DB::transaction(function () use ($causa, $actuacion): void {
            $causaBloqueada = Causa::query()->lockForUpdate()->findOrFail($causa->id);
            $actuacionBloqueada = Actuacion::query()
                ->whereBelongsTo($causaBloqueada)
                ->lockForUpdate()
                ->findOrFail($actuacion->id);
            $debeSincronizar = $actuacionBloqueada->estado_procesal_id !== null;

            $actuacionBloqueada->delete();

            if ($debeSincronizar) {
                $this->sincronizarEstado->handle($causaBloqueada);
            }
        });
    }
}
