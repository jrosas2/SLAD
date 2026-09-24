<?php

namespace App\Actions\Causas;

use App\Models\Causa;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportarResponsableHistorico
{
    public function handle(User $actor, Causa $causa, ?string $codigoACargo): ?string
    {
        Gate::forUser($actor)->authorize('importHistorical', Causa::class);

        $codigo = blank($codigoACargo) ? null : Str::upper(trim($codigoACargo));
        $responsable = $codigo === null
            ? null
            : User::query()->where('codigo', $codigo)->first();

        if ($codigo !== null && $responsable === null) {
            $warning = "No existe un usuario con el código histórico {$codigo}; la causa quedó sin responsable.";

            Log::warning($warning, ['causa_id' => $causa->id, 'codigo' => $codigo]);
            $causa->responsable()->dissociate();
            $causa->save();

            return $warning;
        }

        $causa->responsable()->associate($responsable);
        $causa->save();

        return null;
    }
}
