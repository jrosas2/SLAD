<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\MovimientoFinanciero;
use App\Models\User;

class MovimientoFinancieroPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::MovimientosVer->value);
    }

    public function view(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can(Permiso::MovimientosVer->value) && $this->canAccessCausa($user, $movimientoFinanciero);
    }

    public function create(User $user): bool
    {
        return $user->can(Permiso::MovimientosCrear->value);
    }

    public function update(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can(Permiso::MovimientosEditar->value) && $this->canAccessCausa($user, $movimientoFinanciero);
    }

    public function delete(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can(Permiso::MovimientosEliminar->value) && $this->canAccessCausa($user, $movimientoFinanciero);
    }

    public function restore(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can(Permiso::MovimientosEliminar->value) && $this->canAccessCausa($user, $movimientoFinanciero);
    }

    public function forceDelete(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can(Permiso::MovimientosEliminar->value) && $this->canAccessCausa($user, $movimientoFinanciero);
    }

    private function canAccessCausa(User $user, MovimientoFinanciero $movimientoFinanciero): bool
    {
        return $user->can('view', $movimientoFinanciero->loadMissing('causa')->causa);
    }
}
