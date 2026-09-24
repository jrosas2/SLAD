<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Enums\AccionAuditoria;
use App\Services\AuditoriaService;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Roles y permisos')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $name = '';
    public string $description = '';

    /** @var list<string> */
    public array $selectedPermissions = [];

    public bool $showRoleModal = false;
    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingRoleId = null;

    #[Locked]
    public ?int $deletingRoleId = null;

    public function mount(): void
    {
        Gate::authorize(Permiso::RolesVer->value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function roles(): LengthAwarePaginator
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->when($this->search !== '', function ($query): void {
                $search = '%'.trim($this->search).'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $search)
                    ->orWhere('description', 'like', $search));
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [RolUsuario::Administrador->value])
            ->orderBy('name')
            ->paginate(10);
    }

    /** @return array<string, list<Permiso>> */
    #[Computed]
    public function permissionGroups(): array
    {
        return Permiso::agrupados();
    }

    public function openCreateModal(): void
    {
        Gate::authorize(Permiso::RolesCrear->value);
        $this->resetForm();
        $this->showRoleModal = true;
    }

    public function openEditModal(int $roleId): void
    {
        Gate::authorize(Permiso::RolesEditar->value);
        $role = Role::query()->with('permissions:id,name')->findOrFail($roleId);

        $this->resetValidation();
        $this->editingRoleId = $role->id;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->selectedPermissions = $role->permissions->pluck('name')->all();
        $this->showRoleModal = true;
    }

    public function selectModule(string $module): void
    {
        Gate::authorize(Permiso::RolesAsignarPermisos->value);

        foreach ($this->permissionGroups()[$module] ?? [] as $permission) {
            $this->selectedPermissions[] = $permission->value;
        }

        $this->selectedPermissions = array_values(array_unique($this->selectedPermissions));
    }

    public function selectAllPermissions(): void
    {
        Gate::authorize(Permiso::RolesAsignarPermisos->value);
        $this->selectedPermissions = array_values(array_map(
            fn (Permiso $permission): string => $permission->value,
            Permiso::cases(),
        ));
    }

    public function clearPermissions(): void
    {
        Gate::authorize(Permiso::RolesAsignarPermisos->value);
        $this->selectedPermissions = $this->isEditingAdministrator()
            ? array_map(fn (Permiso $permission): string => $permission->value, Permiso::cases())
            : [];
    }

    public function saveRole(): void
    {
        Gate::authorize($this->editingRoleId === null ? Permiso::RolesCrear->value : Permiso::RolesEditar->value);
        $this->name = Str::upper(Str::squish($this->name));
        $this->description = Str::squish($this->description);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:125', Rule::unique(Role::class, 'name')->where('guard_name', 'web')->ignore($this->editingRoleId)],
            'description' => ['nullable', 'string', 'max:500'],
            'selectedPermissions' => ['array'],
            'selectedPermissions.*' => ['string', Rule::enum(Permiso::class)],
        ], attributes: [
            'name' => 'nombre',
            'description' => 'descripción',
            'selectedPermissions' => 'permisos',
        ]);

        $role = $this->editingRoleId === null
            ? Role::query()->create(['name' => $validated['name'], 'guard_name' => 'web', 'description' => $validated['description'] ?: null])
            : Role::query()->findOrFail($this->editingRoleId);
        $previousPermissions = $role->exists ? $role->permissions()->pluck('name')->sort()->values()->all() : [];

        $role->update($role->esAdministrador()
            ? ['description' => $validated['description'] ?: null]
            : ['name' => $validated['name'], 'description' => $validated['description'] ?: null]);

        if (Gate::allows(Permiso::RolesAsignarPermisos->value)) {
            $permissions = $role->esAdministrador()
                ? array_map(fn (Permiso $permission): string => $permission->value, Permiso::cases())
                : $validated['selectedPermissions'];
            $role->syncPermissions($permissions);
            $currentPermissions = $role->permissions()->pluck('name')->sort()->values()->all();

            if ($previousPermissions !== $currentPermissions) {
                app(AuditoriaService::class)->record(
                    $role,
                    AccionAuditoria::Modificado,
                    ['permissions' => $previousPermissions],
                    ['permissions' => $currentPermissions],
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->showRoleModal = false;
        $this->resetForm();
        unset($this->roles);
        Flux::toast(variant: 'success', text: 'Rol guardado correctamente.');
    }

    public function confirmDelete(int $roleId): void
    {
        Gate::authorize(Permiso::RolesEliminar->value);
        $role = Role::query()->withCount('users')->findOrFail($roleId);

        if ($role->esAdministrador()) {
            throw ValidationException::withMessages(['role' => 'El rol ADMINISTRADOR es institucional y no puede eliminarse.']);
        }

        if ($role->users_count > 0) {
            throw ValidationException::withMessages(['role' => 'No puedes eliminar un rol mientras tenga usuarios asignados.']);
        }

        $this->deletingRoleId = $role->id;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        Gate::authorize(Permiso::RolesEliminar->value);
        $role = Role::query()->withCount('users')->findOrFail($this->deletingRoleId);

        if ($role->esAdministrador() || $role->users_count > 0) {
            throw ValidationException::withMessages(['role' => 'Este rol está protegido o todavía tiene usuarios asignados.']);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->showDeleteModal = false;
        $this->deletingRoleId = null;
        unset($this->roles);
        Flux::toast(variant: 'success', text: 'Rol eliminado correctamente.');
    }

    public function isEditingAdministrator(): bool
    {
        return $this->editingRoleId !== null
            && Role::query()->whereKey($this->editingRoleId)->where('name', RolUsuario::Administrador->value)->exists();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingRoleId = null;
        $this->name = '';
        $this->description = '';
        $this->selectedPermissions = [];
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end dark:border-zinc-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Seguridad</flux:badge>
            <flux:heading size="xl" level="1">Roles y permisos</flux:heading>
            <flux:text>Define perfiles de acceso sin modificar el código de la plataforma.</flux:text>
        </div>
        @can(\App\Enums\Permiso::RolesCrear->value)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">Crear rol</flux:button>
        @endcan
    </div>

    @error('role')
        <flux:callout variant="danger" icon="exclamation-circle" :text="$message" />
    @enderror

    <flux:card class="grid gap-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div><flux:heading size="lg">Perfiles registrados</flux:heading><flux:text>Los cambios se aplican de inmediato a todos los usuarios del rol.</flux:text></div>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar rol" class="sm:max-w-xs" clearable />
        </div>
        <flux:table :paginate="$this->roles">
            <flux:table.columns>
                <flux:table.column>Rol</flux:table.column><flux:table.column>Estado</flux:table.column><flux:table.column>Permisos</flux:table.column><flux:table.column>Usuarios</flux:table.column><flux:table.column align="end">Acciones</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell variant="strong"><div class="grid"><span>{{ $role->name }}</span><span class="font-normal text-zinc-500">{{ $role->description ?: 'Sin descripción' }}</span></div></flux:table.cell>
                        <flux:table.cell><flux:badge :color="$role->esAdministrador() ? 'blue' : 'green'" size="sm">{{ $role->esAdministrador() ? 'Protegido' : 'Activo' }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $role->permissions_count }}</flux:table.cell>
                        <flux:table.cell>{{ $role->users_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can(\App\Enums\Permiso::RolesEditar->value)<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $role->id }})">Editar</flux:button>@endcan
                                @can(\App\Enums\Permiso::RolesEliminar->value)
                                    @unless ($role->esAdministrador())<flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $role->id }})" :disabled="$role->users_count > 0">Eliminar</flux:button>@endunless
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5" class="py-10 text-center">No se encontraron roles.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="role-form" wire:model="showRoleModal" class="md:min-w-2xl">
        <form wire:submit="saveRole" class="grid gap-6">
            <div><flux:heading size="lg">{{ $editingRoleId === null ? 'Crear rol' : 'Editar rol' }}</flux:heading><flux:text>Asigna únicamente las capacidades necesarias para este perfil.</flux:text></div>
            <flux:input wire:model="name" label="Nombre del rol" required maxlength="125" :disabled="$this->isEditingAdministrator()" />
            <flux:textarea wire:model="description" label="Descripción" maxlength="500" rows="2" />

            @can(\App\Enums\Permiso::RolesAsignarPermisos->value)
                <div class="grid gap-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="sm">Permisos</flux:heading>
                        <div class="flex gap-2"><flux:button type="button" size="sm" variant="ghost" wire:click="selectAllPermissions">Seleccionar todos</flux:button><flux:button type="button" size="sm" variant="ghost" wire:click="clearPermissions">Limpiar</flux:button></div>
                    </div>
                    <div class="max-h-96 space-y-4 overflow-y-auto rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        @foreach ($this->permissionGroups as $module => $permissions)
                            <section class="grid gap-3" wire:key="permission-module-{{ Str::slug($module) }}">
                                <div class="flex items-center justify-between gap-2"><flux:heading size="sm">{{ $module }}</flux:heading><flux:button type="button" size="xs" variant="ghost" wire:click="selectModule(@js($module))">Marcar módulo</flux:button></div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    @foreach ($permissions as $permission)
                                        <flux:checkbox wire:model="selectedPermissions" value="{{ $permission->value }}" :label="$permission->etiqueta()" :description="$permission->value" :disabled="$this->isEditingAdministrator()" />
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endcan

            @if ($this->isEditingAdministrator())
                <flux:callout variant="info" icon="shield-check" text="ADMINISTRADOR es un rol institucional: conserva su nombre y todos los permisos del sistema." />
            @endif
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close><flux:button variant="primary" type="submit">Guardar rol</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal name="delete-role" wire:model="showDeleteModal" class="md:min-w-md">
        <div class="grid gap-6">
            <div><flux:heading size="lg">Eliminar rol</flux:heading><flux:text>Esta acción no se puede deshacer.</flux:text></div>
            <flux:callout variant="warning" icon="exclamation-triangle" text="El rol será eliminado definitivamente. Los roles con usuarios asignados no pueden eliminarse." />
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost" type="button">Cancelar</flux:button></flux:modal.close><flux:button variant="danger" type="button" wire:click="deleteRole">Eliminar</flux:button></div>
        </div>
    </flux:modal>
</div>
