<x-layouts::app title="Panel principal">
    @can(\App\Enums\Permiso::DashboardVer->value)
        <livewire:pages::dashboard />
    @else
        <div class="flex w-full flex-1 flex-col gap-6">
            <div class="grid gap-2 border-b border-[#c5c6cd] pb-6 dark:border-slate-700">
                <flux:badge color="blue" size="sm" class="w-fit">Acceso de consulta</flux:badge>
                <flux:heading size="xl" level="1">Panel principal</flux:heading>
                <flux:text>Tu perfil permite consultar expedientes jurídicos, pero no estadísticas institucionales.</flux:text>
            </div>

            <flux:card class="grid max-w-2xl gap-4">
                <div class="flex items-center gap-3">
                    <flux:icon.briefcase class="size-7 text-[#006a61] dark:text-teal-300" />
                    <flux:heading size="lg">Consulta de causas</flux:heading>
                </div>
                <flux:text>Accede al listado de causas para revisar su información general, actuaciones y movimientos financieros.</flux:text>
                <flux:button variant="primary" icon="briefcase" :href="route('causas.index')" wire:navigate class="w-fit">Ir a causas</flux:button>
            </flux:card>
        </div>
    @endcan
</x-layouts::app>
