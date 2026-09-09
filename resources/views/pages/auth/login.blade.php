<x-layouts::auth title="Iniciar sesión">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Ingresa a SLAD" description="Utiliza tu R.U.T. y contraseña institucional." />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- RUT -->
            <flux:input
                name="rut"
                label="R.U.T."
                :value="old('rut')"
                type="text"
                required
                autofocus
                autocomplete="username"
                inputmode="text"
                placeholder="12.345.678-5"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    label="Contraseña"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Contraseña"
                    viewable
                    data-preserve-case
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        ¿Olvidaste tu contraseña?
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" label="Recordarme" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    Iniciar sesión
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
