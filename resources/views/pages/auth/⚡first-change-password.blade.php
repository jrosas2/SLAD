<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Cambiar contraseña inicial')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if (! Auth::user()?->debe_cambiar_password) {
            $this->redirect(route('dashboard', absolute: false), navigate: true);
        }
    }

    public function changePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ], attributes: [
                'current_password' => 'contraseña temporal',
                'password' => 'nueva contraseña',
            ]);
        } catch (ValidationException $exception) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $exception;
        }

        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        $user->update([
            'password' => $validated['password'],
            'debe_cambiar_password' => false,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Crea tu contraseña personal"
        description="Por seguridad, debes reemplazar la contraseña temporal antes de continuar en SLAD."
    />

    <flux:callout variant="warning" icon="key" text="La nueva contraseña será la que utilizarás en tus próximos ingresos." />

    <form wire:submit="changePassword" class="flex flex-col gap-6">
        <flux:input
            wire:model="current_password"
            label="Contraseña temporal"
            type="password"
            required
            autofocus
            autocomplete="current-password"
            viewable
        />

        <flux:input
            wire:model="password"
            label="Nueva contraseña"
            type="password"
            required
            autocomplete="new-password"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            label="Confirmar nueva contraseña"
            type="password"
            required
            autocomplete="new-password"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />

        <flux:button variant="primary" type="submit" class="w-full" data-test="change-initial-password">
            Guardar y continuar
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button variant="ghost" type="submit" class="w-full">Cerrar sesión</flux:button>
    </form>
</div>
