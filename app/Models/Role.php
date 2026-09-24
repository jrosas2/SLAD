<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Spatie\Permission\Models\Role as SpatieRole;

/** @property string|null $description */
#[Fillable(['name', 'guard_name', 'description'])]
class Role extends SpatieRole
{
    use Auditable;

    public function esAdministrador(): bool
    {
        return $this->name === 'ADMINISTRADOR';
    }
}
