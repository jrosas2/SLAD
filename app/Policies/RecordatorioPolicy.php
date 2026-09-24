<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Recordatorio;
use App\Models\User;

class RecordatorioPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::RecordatoriosVer->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Recordatorio $recordatorio): bool
    {
        return $user->can(Permiso::RecordatoriosVer->value) && $this->canAccessCausa($user, $recordatorio);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Permiso::RecordatoriosCrear->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Recordatorio $recordatorio): bool
    {
        return $user->can(Permiso::RecordatoriosEditar->value) && $this->canAccessCausa($user, $recordatorio);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Recordatorio $recordatorio): bool
    {
        return false;
    }

    public function cancel(User $user, Recordatorio $recordatorio): bool
    {
        return $user->can(Permiso::RecordatoriosCancelar->value) && $this->canAccessCausa($user, $recordatorio);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Recordatorio $recordatorio): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Recordatorio $recordatorio): bool
    {
        return false;
    }

    private function canAccessCausa(User $user, Recordatorio $recordatorio): bool
    {
        return $user->can('view', $recordatorio->loadMissing('causa')->causa);
    }
}
