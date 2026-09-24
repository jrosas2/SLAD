<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Actuacion;
use App\Models\User;

class ActuacionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::ActuacionesVer->value);
    }

    public function view(User $user, Actuacion $actuacion): bool
    {
        return $user->can(Permiso::ActuacionesVer->value) && $this->canAccessCausa($user, $actuacion);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::ActuacionesCrear->value);
    }

    public function update(User $user, Actuacion $actuacion): bool
    {
        return $user->can(Permiso::ActuacionesEditar->value) && $this->canAccessCausa($user, $actuacion);
    }

    public function delete(User $user, Actuacion $actuacion): bool
    {
        return $user->can(Permiso::ActuacionesEliminar->value) && $this->canAccessCausa($user, $actuacion);
    }

    public function restore(User $user, Actuacion $actuacion): bool
    {
        return $user->can(Permiso::ActuacionesEliminar->value) && $this->canAccessCausa($user, $actuacion);
    }

    public function forceDelete(User $user, Actuacion $actuacion): bool
    {
        return $user->can(Permiso::ActuacionesEliminar->value) && $this->canAccessCausa($user, $actuacion);
    }

    private function canAccessCausa(User $user, Actuacion $actuacion): bool
    {
        return $user->can('view', $actuacion->loadMissing('causa')->causa);
    }
}
