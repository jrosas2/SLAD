<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Causa;
use App\Models\User;

class CausaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::CausasVer->value);
    }

    public function view(User $user, Causa $causa): bool
    {
        return $this->canAccess($user, $causa);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::CausasCrear->value);
    }

    public function update(User $user, Causa $causa): bool
    {
        return $user->can(Permiso::CausasEditar->value) && $this->canAccess($user, $causa);
    }

    public function assignResponsible(User $user, Causa $causa): bool
    {
        return $user->can(Permiso::CausasAsignarResponsable->value);
    }

    public function importHistorical(User $user): bool
    {
        return $user->can(Permiso::ImportacionesEjecutar->value);
    }

    public function delete(User $user, Causa $causa): bool
    {
        return $user->can(Permiso::CausasEliminar->value) && $this->canAccess($user, $causa);
    }

    public function restore(User $user, Causa $causa): bool
    {
        return $user->can(Permiso::CausasEliminar->value) && $this->canAccess($user, $causa);
    }

    public function forceDelete(User $user, Causa $causa): bool
    {
        return $user->can(Permiso::CausasEliminar->value) && $this->canAccess($user, $causa);
    }

    private function canAccess(User $user, Causa $causa): bool
    {
        if ($user->can(Permiso::CausasVerTodas->value)) {
            return true;
        }

        return $user->can(Permiso::CausasVer->value) && $causa->responsable_id === $user->id;
    }
}
