<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#f8f9ff] dark:bg-[#08111f]">
        <flux:sidebar sticky collapsible="mobile" class="slad-sidebar border-e border-[#1d344d] bg-[#091426] text-slate-200 dark:border-[#1d344d] dark:bg-[#091426]">
            <flux:sidebar.header class="border-b border-white/10 pb-4">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Administración jurídica" class="grid">
                    @can(\App\Enums\Permiso::DashboardVer->value)
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            Panel principal
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Permiso::UsuariosVer->value)
                        <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                            Usuarios
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Permiso::RolesVer->value)
                        <flux:sidebar.item icon="shield-check" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                            Roles y permisos
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Permiso::ImportacionesVer->value)
                        <flux:sidebar.item icon="arrow-up-tray" :href="route('admin.importaciones.index')" :current="request()->routeIs('admin.importaciones.*')" wire:navigate>
                            Importación histórica
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Permiso::AuditoriaVer->value)
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.auditoria.index')" :current="request()->routeIs('admin.auditoria.*')" wire:navigate>
                            Bitácora de auditoría
                        </flux:sidebar.item>
                    @endcan
                    @can(\App\Enums\Permiso::CausasVer->value)
                        <flux:sidebar.item icon="briefcase" :href="route('causas.index')" :current="request()->routeIs('causas.*')" wire:navigate>
                            Causas
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @can(\App\Enums\Permiso::CatalogosVer->value)
                    <flux:sidebar.group heading="Catálogos" class="grid">
                        <flux:sidebar.item icon="briefcase" :href="route('catalogos.simple.index', ['catalogo' => 'materias'])" :current="request()->is('catalogos/materias')" wire:navigate>Materias</flux:sidebar.item>
                        <flux:sidebar.item icon="rectangle-stack" :href="route('catalogos.submaterias.index')" :current="request()->routeIs('catalogos.submaterias.index')" wire:navigate>Submaterias</flux:sidebar.item>
                        <flux:sidebar.item icon="map-pin" :href="route('catalogos.simple.index', ['catalogo' => 'ciudades'])" :current="request()->is('catalogos/ciudades')" wire:navigate>Ciudades</flux:sidebar.item>
                        <flux:sidebar.item icon="building-library" :href="route('catalogos.juzgados.index')" :current="request()->routeIs('catalogos.juzgados.index')" wire:navigate>Juzgados</flux:sidebar.item>
                        <flux:sidebar.item icon="map" :href="route('catalogos.simple.index', ['catalogo' => 'direcciones'])" :current="request()->is('catalogos/direcciones')" wire:navigate>Direcciones</flux:sidebar.item>
                        <flux:sidebar.item icon="queue-list" :href="route('catalogos.simple.index', ['catalogo' => 'estados-procesales'])" :current="request()->is('catalogos/estados-procesales')" wire:navigate>Estados procesales</flux:sidebar.item>
                        <flux:sidebar.item icon="check-badge" :href="route('catalogos.simple.index', ['catalogo' => 'estados-causa'])" :current="request()->is('catalogos/estados-causa')" wire:navigate>Estados de causa</flux:sidebar.item>
                        <flux:sidebar.item icon="bolt" :href="route('catalogos.simple.index', ['catalogo' => 'acciones'])" :current="request()->is('catalogos/acciones')" wire:navigate>Acciones</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

            </flux:sidebar.nav>

            <flux:spacer />

            <flux:text class="px-3 text-xs text-slate-400!">Sistema Logístico de Administración de Causas</flux:text>

            <div class="hidden px-3 pt-3 lg:block">
                <livewire:sidebar.server-clock :key="'server-clock-desktop'" />
            </div>
            <div class="hidden px-3 py-2 lg:block">
                <livewire:notifications.bell :key="'notification-bell-desktop'" />
            </div>
            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-[#c5c6cd] bg-[#f8f9ff] lg:hidden dark:border-slate-700 dark:bg-[#08111f]">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <livewire:notifications.bell :key="'notification-bell-mobile'" />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            Configuración
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            Cerrar sesión
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
