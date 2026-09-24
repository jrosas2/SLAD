<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\DocumentoCausa;
use App\Models\User;

class DocumentoCausaPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permiso::DocumentosVer->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DocumentoCausa $documentoCausa): bool
    {
        return $user->can(Permiso::DocumentosVer->value) && $this->canAccessCausa($user, $documentoCausa);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Permiso::DocumentosCrear->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DocumentoCausa $documentoCausa): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DocumentoCausa $documentoCausa): bool
    {
        return $user->can(Permiso::DocumentosEliminar->value) && $this->canAccessCausa($user, $documentoCausa);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DocumentoCausa $documentoCausa): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DocumentoCausa $documentoCausa): bool
    {
        return false;
    }

    private function canAccessCausa(User $user, DocumentoCausa $documentoCausa): bool
    {
        return $user->can('view', $documentoCausa->loadMissing('causa')->causa);
    }
}
