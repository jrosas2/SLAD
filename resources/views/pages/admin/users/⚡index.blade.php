<?php

use App\Enums\Permiso;
use App\Enums\RolUsuario;
use App\Enums\AccionAuditoria;
use App\Concerns\PasswordValidationRules;
use App\Models\Role;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Services\AuditoriaService;
use App\Support\ChileanRut;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Administrar usuarios')] class extends Component {
    use PasswordValidationRules, WithPagination;

    public string $search = '';

    public string $name = '';

    public string $codigo = '';

    public string $rut = '';

    public string $email = '';

    public string $telefono = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $roleName = RolUsuario::Consulta->value;

    public bool $activo = true;

    public bool $showUserModal = false;

    public bool $showPasswordResetModal = false;

    #[Locked]
    public ?int $editingUserId = null;

    #[Locked]
    public ?int $passwordResetUserId = null;

    #[Locked]
    public ?string $passwordResetUserName = null;

    #[Locked]
    public ?string $temporaryPassword = null;

    #[Locked]
    public ?string $temporaryPasswordUser = null;

    public function mount(): void
    {
        Gate::authorize(Permiso::UsuariosVer->value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->select(['id', 'name'])->orderBy('name')->get();
    }

    #[Computed]
    public function usuarios(): LengthAwarePaginator
    {
        return User::query()
            ->with('roles:id,name')
            ->when($this->search !== '', function ($query): void {
                $search = '%'.trim($this->search).'%';
                $normalizedRut = ChileanRut::normalize($this->search);

                $query->where(function ($query) use ($search, $normalizedRut): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('codigo', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('rut', 'like', $normalizedRut === null ? $search : '%'.$normalizedRut.'%');
                });
            })
            ->orderBy('name')
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        Gate::authorize(Permiso::UsuariosCrear->value);

        $this->resetForm();
        $this->showUserModal = true;
    }

    public function openEditModal(int $userId): void
    {
        Gate::authorize(Permiso::UsuariosEditar->value);

        $user = User::query()->findOrFail($userId);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->codigo = $user->codigo ?? '';
        $this->rut = $user->rutFormateado() ?? '';
        $this->email = $user->email;
        $this->telefono = $user->telefono ?? '';
        $this->roleName = $user->getRoleNames()->first() ?? RolUsuario::Consulta->value;
        $this->activo = $user->activo;
        $this->showUserModal = true;
    }

    public function saveUser(): void
    {
        Gate::authorize($this->editingUserId === null ? Permiso::UsuariosCrear->value : Permiso::UsuariosEditar->value);

        $this->name = trim($this->name);
        $this->codigo = Str::upper(trim($this->codigo));
        $this->email = Str::lower(trim($this->email));
        $this->telefono = trim($this->telefono);

        if (($normalizedRut = ChileanRut::normalize($this->rut)) !== null) {
            $this->rut = $normalizedRut;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:20', Rule::unique(User::class)->ignore($this->editingUserId)],
            'rut' => ['required', 'string', new ValidChileanRut, Rule::unique(User::class, 'rut')->ignore($this->editingUserId)],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->editingUserId),
            ],
            'telefono' => ['nullable', 'string', 'max:30'],
            'roleName' => ['required', 'string', Rule::exists(Role::class, 'name')->where('guard_name', 'web')],
            'activo' => ['required', 'boolean'],
        ];

        if ($this->editingUserId === null) {
            $rules['password'] = $this->passwordRules();
        }

        $validated = $this->validate($rules, attributes: [
            'name' => 'nombre',
            'codigo' => 'código',
            'rut' => 'R.U.T.',
            'email' => 'correo electrónico',
            'telefono' => 'teléfono',
            'password' => 'contraseña',
            'roleName' => 'perfil o rol',
            'activo' => 'estado',
        ]);

        $validated['codigo'] = $validated['codigo'] === '' ? null : $validated['codigo'];
        $validated['rut'] = ChileanRut::normalizeOrFail($validated['rut']);
        $validated['telefono'] = $validated['telefono'] === '' ? null : $validated['telefono'];

        if (! Gate::allows(Permiso::UsuariosAsignarRoles->value)) {
            $validated['roleName'] = $this->editingUserId === null
                ? RolUsuario::Consulta->value
                : User::query()->findOrFail($this->editingUserId)->getRoleNames()->first() ?? RolUsuario::Consulta->value;
        }

        if ($this->editingUserId === null) {
            $this->createUser($validated);
        } else {
            $this->updateUser($validated);
        }

        $this->showUserModal = false;
        $this->resetForm();
        unset($this->usuarios);
    }

    public function dismissTemporaryPassword(): void
    {
        $this->temporaryPassword = null;
        $this->temporaryPasswordUser = null;
    }

    public function openPasswordResetModal(int $userId): void
    {
        Gate::authorize(Permiso::UsuariosEditar->value);

        $user = User::query()->findOrFail($userId);

        $this->passwordResetUserId = $user->id;
        $this->passwordResetUserName = $user->name;
        $this->showPasswordResetModal = true;
    }

    public function regenerateTemporaryPassword(): void
    {
        Gate::authorize(Permiso::UsuariosEditar->value);

        $user = User::query()->findOrFail($this->passwordResetUserId);
        $temporaryPassword = Str::password(16);

        $user->forceFill([
            'password' => $temporaryPassword,
            'debe_cambiar_password' => true,
            'remember_token' => Str::random(60),
        ])->save();

        $this->temporaryPassword = $temporaryPassword;
        $this->temporaryPasswordUser = $user->name;
        $this->showPasswordResetModal = false;
        $this->passwordResetUserId = null;
        $this->passwordResetUserName = null;
        unset($this->usuarios);
    }

    /** @param array{name: string, codigo: string|null, rut: string, email: string, telefono: string|null, password: string, roleName: string, activo: bool} $validated */
    private function createUser(array $validated): void
    {
        $user = new User([
            'name' => $validated['name'],
            'codigo' => $validated['codigo'],
            'rut' => $validated['rut'],
            'email' => $validated['email'],
            'telefono' => $validated['telefono'],
            'activo' => $validated['activo'],
            'password' => $validated['password'],
            'debe_cambiar_password' => true,
        ]);
        $user->email_verified_at = now();
        $user->save();
        $user->syncRoles([$validated['roleName']]);
        app(AuditoriaService::class)->record($user, AccionAuditoria::Modificado, ['roles' => []], ['roles' => [$validated['roleName']]]);
    }

    /** @param array{name: string, codigo: string|null, rut: string, email: string, telefono: string|null, roleName: string, activo: bool} $validated */
    private function updateUser(array $validated): void
    {
        $user = User::query()->findOrFail($this->editingUserId);
        $previousRoles = $user->getRoleNames()->sort()->values()->all();

        if ($user->is(auth()->user())) {
            $validated['roleName'] = $user->getRoleNames()->first() ?? RolUsuario::Administrador->value;
            $validated['activo'] = true;
        }

        if ($user->activo !== $validated['activo']) {
            Gate::authorize(Permiso::UsuariosDesactivar->value);
        }

        $this->ensureAdministrativeContinuity($user, $validated['roleName'], $validated['activo']);

        $user->update([
            'name' => $validated['name'],
            'codigo' => $validated['codigo'],
            'rut' => $validated['rut'],
            'email' => $validated['email'],
            'telefono' => $validated['telefono'],
            'activo' => $validated['activo'],
        ]);
        $user->syncRoles([$validated['roleName']]);
        $currentRoles = $user->getRoleNames()->sort()->values()->all();

        if ($previousRoles !== $currentRoles) {
            app(AuditoriaService::class)->record($user, AccionAuditoria::Modificado, ['roles' => $previousRoles], ['roles' => $currentRoles]);
        }

        Flux::toast(variant: 'success', text: 'Usuario actualizado correctamente.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingUserId = null;
        $this->name = '';
        $this->codigo = '';
        $this->rut = '';
        $this->email = '';
        $this->telefono = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->roleName = RolUsuario::Consulta->value;
        $this->activo = true;
    }

    private function ensureAdministrativeContinuity(User $user, string $newRoleName, bool $active): void
    {
        if (! $user->hasRole(RolUsuario::Administrador->value)
            || ($newRoleName === RolUsuario::Administrador->value && $active)) {
            return;
        }

        $otherActiveAdministrators = User::query()
            ->whereKeyNot($user->getKey())
            ->where('activo', true)
            ->role(RolUsuario::Administrador->value)
            ->exists();

        if (! $otherActiveAdministrators) {
            throw ValidationException::withMessages([
                'roleName' => 'No puedes dejar al sistema sin un administrador activo.',
            ]);
        }
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-zinc-200 pb-6 sm:flex-row sm:items-end dark:border-zinc-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Administración</flux:badge>
            <flux:heading size="xl" level="1">Usuarios y roles</flux:heading>
            <flux:text>Crea cuentas y controla el nivel de acceso de cada integrante.</flux:text>
        </div>

        @can(\App\Enums\Permiso::UsuariosCrear->value)
            <flux:button variant="primary" icon="user-plus" wire:click="openCreateModal">Crear usuario</flux:button>
        @endcan
    </div>

    @if ($temporaryPassword !== null)
        <flux:callout variant="success" icon="key" heading="Contraseña temporal generada">
            <div class="grid gap-3">
                <flux:text>
                    Entrega esta contraseña a <strong>{{ $temporaryPasswordUser }}</strong>. Solo se muestra ahora y deberá cambiarla al iniciar sesión.
                </flux:text>
                <code data-test="temporary-password" class="w-fit select-all rounded-lg bg-white px-3 py-2 font-mono text-base font-semibold text-zinc-900 ring-1 ring-green-300 dark:bg-zinc-950 dark:text-white">
                    {{ $temporaryPassword }}
                </code>
                <flux:button size="sm" variant="ghost" class="w-fit" wire:click="dismissTemporaryPassword">
                    Ya la guardé
                </flux:button>
            </div>
        </flux:callout>
    @endif

    <flux:card class="grid gap-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <flux:heading size="lg">Cuentas registradas</flux:heading>
                <flux:text>El rol determina las operaciones disponibles en SLAD.</flux:text>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                placeholder="Buscar por nombre, RUT o correo"
                class="sm:max-w-xs"
                clearable
            />
        </div>

        <flux:table :paginate="$this->usuarios">
            <flux:table.columns>
                <flux:table.column>Nombre</flux:table.column>
                <flux:table.column>R.U.T.</flux:table.column>
                <flux:table.column>Contacto</flux:table.column>
                <flux:table.column>Rol</flux:table.column>
                <flux:table.column>Estado</flux:table.column>
                <flux:table.column align="end">Acciones</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->usuarios as $usuario)
                    @php
                        $roleColor = match (true) {
                            $usuario->hasRole(RolUsuario::Administrador->value) => 'blue',
                            $usuario->hasRole(RolUsuario::Abogado->value) => 'green',
                            default => 'zinc',
                        };
                    @endphp

                    <flux:table.row :key="$usuario->id">
                        <flux:table.cell variant="strong">
                            <div class="grid">
                                <span>{{ $usuario->codigo ? $usuario->codigo.' - ' : '' }}{{ $usuario->name }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $usuario->rutFormateado() ?? 'Pendiente' }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="grid">
                                <span>{{ $usuario->email }}</span>
                                <span class="font-normal text-zinc-500">{{ $usuario->telefono ?: 'Sin teléfono' }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$roleColor" size="sm">{{ $usuario->getRoleNames()->first() ?? 'SIN PERFIL' }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$usuario->activo ? 'green' : 'red'" size="sm">
                                {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can(\App\Enums\Permiso::UsuariosEditar->value)
                                    <flux:button size="sm" variant="ghost" icon="key" wire:click="openPasswordResetModal({{ $usuario->id }})">Nueva clave</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $usuario->id }})">Editar</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-10 text-center">
                            No se encontraron usuarios.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="user-form" wire:model="showUserModal" class="md:min-w-lg">
        <form wire:submit="saveUser" class="grid gap-6">
            <div>
                <flux:heading size="lg">{{ $editingUserId === null ? 'Crear usuario' : 'Editar usuario' }}</flux:heading>
                <flux:text>
                            {{ $editingUserId === null ? 'Define sus credenciales iniciales y perfil de acceso.' : 'Actualiza sus datos de contacto, RUT, rol y estado.' }}
                </flux:text>
            </div>

            <flux:input wire:model="name" label="Nombre completo" required autocomplete="name" />
            <flux:input wire:model="rut" label="R.U.T." required autocomplete="username" placeholder="12.345.678-5" />
            <flux:input wire:model="email" label="Correo electrónico" type="email" required autocomplete="email" />
            <flux:input wire:model="telefono" label="Teléfono" maxlength="30" autocomplete="tel" placeholder="+56 9 1234 5678" />

            @if ($editingUserId === null)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="password" label="Contraseña inicial" type="password" required autocomplete="new-password" viewable />
                    <flux:input wire:model="password_confirmation" label="Confirmar contraseña" type="password" required autocomplete="new-password" viewable />
                </div>
                <flux:callout variant="info" icon="key" text="El usuario deberá cambiar esta contraseña al iniciar sesión por primera vez." />
            @endif

            <flux:select wire:model="roleName" label="Perfil / Rol" :disabled="$editingUserId === auth()->id() || ! auth()->user()->can(\App\Enums\Permiso::UsuariosAsignarRoles->value)">
                @foreach ($this->roles as $role)
                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="activo" label="Cuenta activa" :disabled="$editingUserId === auth()->id()" />

            @if ($editingUserId === auth()->id())
                <flux:callout variant="warning" icon="shield-check" text="No puedes desactivar tu propia cuenta ni quitarte el rol administrador." />
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">
                    {{ $editingUserId === null ? 'Crear usuario' : 'Guardar cambios' }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reset-password" wire:model="showPasswordResetModal" class="md:min-w-md">
        <div class="grid gap-6">
            <div class="grid gap-2">
                <flux:heading size="lg">Generar nueva contraseña temporal</flux:heading>
                <flux:text>
                    Se reemplazará inmediatamente la contraseña de <strong>{{ $passwordResetUserName }}</strong>.
                </flux:text>
            </div>

            <flux:callout
                variant="warning"
                icon="exclamation-triangle"
                text="La contraseña actual dejará de funcionar y el usuario deberá cambiar la nueva clave temporal al ingresar."
            />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="button" wire:click="regenerateTemporaryPassword">
                    Generar nueva clave
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
