<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#f8f9ff] antialiased dark:bg-[#08111f]">
        <div class="grid min-h-svh lg:grid-cols-[minmax(0,0.9fr)_minmax(28rem,1.1fr)]">
            <section class="relative hidden overflow-hidden bg-[#091426] p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14">
                <a href="{{ route('home') }}" class="relative z-10 flex items-center gap-3 text-xl font-bold tracking-tight" wire:navigate>
                    <span class="flex size-10 items-center justify-center rounded-sm bg-[#006a61]">
                        <flux:icon.scale variant="solid" class="size-6" />
                    </span>
                    SLAD
                </a>

                <div class="relative z-10 max-w-lg">
                    <div class="mb-6 inline-flex items-center gap-2 rounded border border-teal-700/60 bg-teal-950/60 px-3 py-1.5 text-xs font-semibold tracking-wide text-teal-200">
                        <flux:icon.shield-check class="size-4" />
                        Acceso seguro a la plataforma
                    </div>
                    <h1 class="text-4xl font-bold leading-tight tracking-tight xl:text-5xl">
                        Gestión jurídica centralizada y trazable.
                    </h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-slate-300">
                        Administra causas, actuaciones, responsables y movimientos desde un único espacio de trabajo.
                    </p>
                </div>

                <p class="relative z-10 text-sm text-slate-400">Sistema Logístico de Administración de Derecho</p>

                <div class="pointer-events-none absolute -right-40 top-1/2 size-96 -translate-y-1/2 rounded-full border border-teal-500/15"></div>
                <div class="pointer-events-none absolute -right-24 top-1/2 size-64 -translate-y-1/2 rounded-full border border-teal-500/20"></div>
            </section>

            <main class="slad-auth-surface flex min-h-svh items-center justify-center px-5 py-10 sm:px-8 lg:min-h-0 lg:px-12">
                <div class="w-full max-w-md">
                    <a href="{{ route('home') }}" class="mb-8 flex items-center justify-center gap-3 text-xl font-bold tracking-tight text-[#091426] lg:hidden dark:text-white" wire:navigate>
                        <span class="flex size-10 items-center justify-center rounded-sm bg-[#006a61] text-white">
                            <flux:icon.scale variant="solid" class="size-6" />
                        </span>
                        SLAD
                    </a>

                    <div class="border border-[#c5c6cd] bg-white p-6 shadow-[0_10px_24px_rgba(9,20,38,0.06)] sm:p-8 dark:border-slate-700 dark:bg-[#0b1728]">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
