<?php

use App\Enums\Permiso;
use App\Models\Ciudad;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Panel principal')] class extends Component
{
    #[Url(as: 'anio', keep: true)]
    public string $year = '';

    #[Url(except: '')]
    public string $materiaFilter = '';

    #[Url(except: '')]
    public string $responsableFilter = '';

    #[Url(except: '')]
    public string $estadoCausaFilter = '';

    #[Url(except: '')]
    public string $estadoProcesalFilter = '';

    #[Url(except: '')]
    public string $ciudadFilter = '';

    #[Url(except: '')]
    public string $juzgadoFilter = '';

    protected DashboardStatsService $dashboardStats;

    public function boot(DashboardStatsService $dashboardStats): void
    {
        $this->dashboardStats = $dashboardStats;
    }

    public function mount(): void
    {
        Gate::authorize(Permiso::DashboardVer->value);

        if ($this->year === '' && ! request()->has('anio')) {
            $this->year = (string) now()->year;
        }
    }

    public function updatedCiudadFilter(): void
    {
        if ($this->juzgadoFilter !== '' && ! Juzgado::query()
            ->whereKey($this->juzgadoFilter)
            ->where('ciudad_id', $this->ciudadFilter)
            ->exists()) {
            $this->juzgadoFilter = '';
        }

        unset($this->juzgados);
    }

    public function clearFilters(): void
    {
        $this->year = '';
        $this->materiaFilter = '';
        $this->responsableFilter = '';
        $this->estadoCausaFilter = '';
        $this->estadoProcesalFilter = '';
        $this->ciudadFilter = '';
        $this->juzgadoFilter = '';
        unset($this->dashboardData, $this->juzgados);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function dashboardData(): array
    {
        return $this->dashboardStats->dashboard($this->filters(), auth()->user());
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return $this->dashboardStats->availableYears(auth()->user());
    }

    /** @return Collection<int, \App\Models\Recordatorio> */
    #[Computed]
    public function proximosRecordatorios(): Collection
    {
        return $this->dashboardStats->proximosRecordatorios(auth()->user());
    }

    /** @return Collection<int, Materia> */
    #[Computed]
    public function materias(): Collection
    {
        return Materia::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function responsables(): Collection
    {
        return User::query()
            ->select(['id', 'codigo', 'name'])
            ->when(! auth()->user()?->can(Permiso::CausasVerTodas->value), fn (Builder $query) => $query->whereKey(auth()->id()))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, EstadoCausa> */
    #[Computed]
    public function estadosCausa(): Collection
    {
        return EstadoCausa::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, EstadoProcesal> */
    #[Computed]
    public function estadosProcesales(): Collection
    {
        return EstadoProcesal::query()->select(['id', 'nombre'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, Ciudad> */
    #[Computed]
    public function ciudades(): Collection
    {
        return Ciudad::query()->select(['id', 'nombre'])->whereHas('juzgados')->orderBy('nombre')->get();
    }

    /** @return Collection<int, Juzgado> */
    #[Computed]
    public function juzgados(): Collection
    {
        return Juzgado::query()
            ->select(['id', 'ciudad_id', 'nombre'])
            ->when($this->ciudadFilter !== '', fn (Builder $query) => $query->where('ciudad_id', $this->ciudadFilter))
            ->orderBy('nombre')
            ->get();
    }

    #[Computed]
    public function activeFiltersCount(): int
    {
        return collect([
            $this->year,
            $this->materiaFilter,
            $this->responsableFilter,
            $this->estadoCausaFilter,
            $this->estadoProcesalFilter,
            $this->ciudadFilter,
            $this->juzgadoFilter,
        ])->filter(fn (string $value): bool => $value !== '')->count();
    }

    public function formatClp(int|float|string $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }

    /**
     * @return array{year: int|null, materiaId: int|null, responsableId: int|null, estadoCausaId: int|null, estadoProcesalId: int|null, ciudadId: int|null, juzgadoId: int|null}
     */
    private function filters(): array
    {
        return [
            'year' => $this->yearId(),
            'materiaId' => $this->filterId($this->materiaFilter),
            'responsableId' => $this->filterId($this->responsableFilter),
            'estadoCausaId' => $this->filterId($this->estadoCausaFilter),
            'estadoProcesalId' => $this->filterId($this->estadoProcesalFilter),
            'ciudadId' => $this->filterId($this->ciudadFilter),
            'juzgadoId' => $this->filterId($this->juzgadoFilter),
        ];
    }

    private function filterId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function yearId(): ?int
    {
        $year = $this->filterId($this->year);

        return $year !== null && $year >= 1900 && $year <= 2100 ? $year : null;
    }
}; ?>

@php
    $data = $this->dashboardData;
    $summary = $data['resumen'];
    $maxMateria = max(1, (int) collect($data['causasPorMateria'])->max('cantidad'));
    $maxResponsable = max(1, (int) collect($data['causasPorResponsable'])->max('cantidad'));
    $maxCausasMes = max(1, (int) collect($data['causasPorMes'])->max('cantidad'));
    $annualVariation = $data['variacionCausasAnual'];
    $annualVariationPercentage = $annualVariation['porcentaje'];
    $annualVariationColor = match (true) {
        $annualVariationPercentage === null => 'zinc',
        $annualVariationPercentage > 0 => 'green',
        $annualVariationPercentage < 0 => 'red',
        default => 'zinc',
    };
    $annualVariationLabel = match (true) {
        $annualVariation['anioActual'] === null => 'Selecciona un año',
        $annualVariationPercentage === null => 'Sin base comparativa',
        $annualVariationPercentage > 0 => '+'.number_format($annualVariationPercentage, 1, ',', '.').'%',
        default => number_format($annualVariationPercentage, 1, ',', '.').'%',
    };
    $maxFinanzasMes = max(1, (int) collect($data['finanzasPorMes'])->max(fn (array $month): int => max($month['ingresos'], $month['egresos'])));
    $vigente = collect($data['causasPorEstado'])->firstWhere('nombre', 'VIGENTE');
    $cerrada = collect($data['causasPorEstado'])->firstWhere('nombre', 'CERRADA');
@endphp

<div class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 lg:flex-row lg:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="green" size="sm" class="w-fit">Información actualizada</flux:badge>
            <flux:heading size="xl" level="1">Panel administrativo y jurídico</flux:heading>
            <flux:text>Indicadores operacionales calculados directamente desde los registros de SLAD.</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:text class="text-sm">Perfil:</flux:text>
            <flux:badge color="zinc">{{ auth()->user()->getRoleNames()->first() ?? 'SIN PERFIL' }}</flux:badge>
        </div>
    </div>

    <flux:card class="grid gap-4">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <flux:heading size="lg">Filtros globales</flux:heading>
                <flux:text>El año usa fecha de ingreso para causas, fecha efectiva para actuaciones y movimientos.</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:badge color="blue" size="sm">{{ $this->activeFiltersCount }} activos</flux:badge>
                <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar</flux:button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:select wire:model.live="year" label="Año">
                <option value="">Todos los años</option>
                @foreach ($this->years as $availableYear)
                    <option value="{{ $availableYear }}">{{ $availableYear }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="materiaFilter" label="Materia">
                <option value="">Todas</option>
                @foreach ($this->materias as $materia)<option value="{{ $materia->id }}">{{ $materia->nombre }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="responsableFilter" label="Responsable">
                <option value="">Todos</option>
                @foreach ($this->responsables as $responsable)<option value="{{ $responsable->id }}">{{ $responsable->etiquetaResponsable() }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="estadoCausaFilter" label="Estado de causa">
                <option value="">Todos</option>
                @foreach ($this->estadosCausa as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="estadoProcesalFilter" label="Estado procesal">
                <option value="">Todos</option>
                @foreach ($this->estadosProcesales as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="ciudadFilter" label="Ciudad">
                <option value="">Todas</option>
                @foreach ($this->ciudades as $ciudad)<option value="{{ $ciudad->id }}">{{ $ciudad->nombre }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="juzgadoFilter" label="Juzgado">
                <option value="">Todos</option>
                @foreach ($this->juzgados as $juzgado)<option value="{{ $juzgado->id }}">{{ $juzgado->nombre }}</option>@endforeach
            </flux:select>
        </div>
    </flux:card>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-60">
        <flux:card class="grid gap-2">
            <div class="flex items-center justify-between gap-3"><span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Total de causas</span><flux:icon.briefcase class="size-5 text-[#006a61] dark:text-teal-300" /></div>
            <p class="text-3xl font-semibold text-[#091426] dark:text-slate-100">{{ $summary['totalCausas'] }}</p>
        </flux:card>
        <flux:card class="grid gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Causas vigentes</span>
            <p class="text-3xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $summary['causasVigentes'] }}</p>
            @if ($vigente)<flux:button size="sm" variant="ghost" :href="route('causas.index', ['estadoCausaFilter' => $vigente['id']])" wire:navigate>Ver causas</flux:button>@endif
        </flux:card>
        <flux:card class="grid gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Causas cerradas</span>
            <p class="text-3xl font-semibold text-zinc-700 dark:text-zinc-300">{{ $summary['causasCerradas'] }}</p>
            @if ($cerrada)<flux:button size="sm" variant="ghost" :href="route('causas.index', ['estadoCausaFilter' => $cerrada['id']])" wire:navigate>Ver causas</flux:button>@endif
        </flux:card>
        <flux:card class="grid gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Monto total demandado</span>
            <p class="text-2xl font-semibold text-[#091426] dark:text-slate-100">{{ $this->formatClp($summary['montoDemandado']) }}</p>
            <flux:badge color="zinc" size="sm" class="w-fit">CLP</flux:badge>
        </flux:card>
    </div>

    <div class="grid gap-4 md:grid-cols-3" wire:loading.class="opacity-60">
        <div class="rounded-sm border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/40"><p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Total ingresos</p><p class="mt-2 text-2xl font-semibold text-emerald-800 dark:text-emerald-200">{{ $this->formatClp($summary['totalIngresos']) }}</p></div>
        <div class="rounded-sm border border-rose-200 bg-rose-50 p-5 dark:border-rose-900 dark:bg-rose-950/40"><p class="text-xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300">Total egresos</p><p class="mt-2 text-2xl font-semibold text-rose-800 dark:text-rose-200">{{ $this->formatClp($summary['totalEgresos']) }}</p></div>
        <div class="rounded-sm border border-[#006a61]/30 bg-[#006a61]/5 p-5 dark:border-teal-700 dark:bg-teal-950/40"><p class="text-xs font-semibold uppercase tracking-wide text-[#006a61] dark:text-teal-300">Saldo financiero</p><p class="mt-2 text-2xl font-semibold {{ $summary['saldo'] < 0 ? 'text-rose-700 dark:text-rose-300' : 'text-[#091426] dark:text-slate-100' }}">{{ $this->formatClp($summary['saldo']) }}</p></div>
    </div>

    <flux:card class="grid gap-5" wire:loading.class="opacity-60">
        <div><flux:heading size="lg">Causas por materia</flux:heading><flux:text>Distribución de expedientes del período seleccionado.</flux:text></div>
        <div class="grid gap-4">
            @forelse ($data['causasPorMateria'] as $item)
                <a wire:key="materia-chart-{{ $item['id'] }}" href="{{ route('causas.index', ['materiaFilter' => $item['id']]) }}" wire:navigate class="grid gap-2 rounded-sm p-2 transition hover:bg-zinc-50 dark:hover:bg-slate-800">
                    <div class="flex justify-between gap-4 text-sm"><span class="font-medium">{{ $item['nombre'] }}</span><span>{{ $item['cantidad'] }}</span></div>
                    <progress class="h-2 w-full accent-[#006a61]" max="{{ $maxMateria }}" value="{{ $item['cantidad'] }}">{{ $item['cantidad'] }}</progress>
                </a>
            @empty
                <flux:text>No existen causas para los filtros seleccionados.</flux:text>
            @endforelse
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2" wire:loading.class="opacity-60">
        <flux:card class="grid content-start gap-5">
            <div><flux:heading size="lg">Estado de las causas</flux:heading><flux:text>Distribución circular y porcentual por estado.</flux:text></div>
            <x-dashboard.donut-chart :items="$data['causasPorEstado']" test-id="circular-chart-estados" empty-message="Sin datos de estados." />
        </flux:card>

        <flux:card class="grid content-start gap-5">
            <div><flux:heading size="lg">Estados procesales</flux:heading><flux:text>Distribución circular de la etapa procesal actual.</flux:text></div>
            <x-dashboard.donut-chart :items="$data['causasPorEstadoProcesal']" test-id="circular-chart-estados-procesales" empty-message="Sin datos de estados procesales." />
        </flux:card>
    </div>

    <flux:card class="grid gap-5" wire:loading.class="opacity-60">
        <div><flux:heading size="lg">Causas por responsable</flux:heading><flux:text>Incluye causas que todavía no tienen profesional asignado.</flux:text></div>
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse ($data['causasPorResponsable'] as $item)
                <div wire:key="responsable-chart-{{ $item['id'] ?? 'none' }}" class="grid gap-2"><div class="flex justify-between gap-4 text-sm"><span class="font-medium">{{ $item['nombre'] }}</span><span>{{ $item['cantidad'] }}</span></div><progress class="h-2 w-full accent-[#006a61]" max="{{ $maxResponsable }}" value="{{ $item['cantidad'] }}">{{ $item['cantidad'] }}</progress></div>
            @empty<flux:text>No existen asignaciones para este período.</flux:text>@endforelse
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2" wire:loading.class="opacity-60">
        <flux:card class="grid content-start gap-5">
            <div><flux:heading size="lg">Ingreso mensual de causas</flux:heading><flux:text>Los meses sin registros se conservan con valor cero.</flux:text></div>
            <div class="grid gap-3">
                @foreach ($data['causasPorMes'] as $month)
                    <div wire:key="causas-mes-{{ $month['mes'] }}" class="grid grid-cols-[2.5rem_1fr_2rem] items-center gap-3 text-sm"><span class="font-medium">{{ $month['nombre'] }}</span><progress class="h-2 w-full accent-[#006a61]" max="{{ $maxCausasMes }}" value="{{ $month['cantidad'] }}">{{ $month['cantidad'] }}</progress><span class="text-right">{{ $month['cantidad'] }}</span></div>
                @endforeach
            </div>
            <div data-testid="annual-cause-variation" class="flex flex-col justify-between gap-3 border-t border-zinc-200 pt-4 sm:flex-row sm:items-center dark:border-slate-700">
                <div class="grid gap-1">
                    <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        Variación respecto al año anterior
                    </span>
                    @if ($annualVariation['anioActual'] !== null)
                        <flux:text>
                            {{ $annualVariation['totalActual'] }} causas en {{ $annualVariation['anioActual'] }} frente a {{ $annualVariation['totalAnterior'] }} en {{ $annualVariation['anioAnterior'] }}.
                        </flux:text>
                    @else
                        <flux:text>Selecciona un año para habilitar la comparación.</flux:text>
                    @endif
                </div>
                <flux:badge :color="$annualVariationColor" size="lg" class="w-fit">{{ $annualVariationLabel }}</flux:badge>
            </div>
        </flux:card>

        <flux:card class="grid content-start gap-5">
            <div><flux:heading size="lg">Ingresos vs. egresos por mes</flux:heading><flux:text>Comparación financiera en pesos chilenos.</flux:text></div>
            <div class="grid gap-4">
                @foreach ($data['finanzasPorMes'] as $month)
                    <div wire:key="finanzas-mes-{{ $month['mes'] }}" class="grid gap-2">
                        <span class="text-xs font-semibold">{{ $month['nombre'] }}</span>
                        <div class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-2 text-xs"><span class="text-emerald-700 dark:text-emerald-300">Ingresos</span><progress class="h-2 w-full accent-emerald-600" max="{{ $maxFinanzasMes }}" value="{{ $month['ingresos'] }}">{{ $month['ingresos'] }}</progress><span>{{ $this->formatClp($month['ingresos']) }}</span></div>
                        <div class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-2 text-xs"><span class="text-rose-700 dark:text-rose-300">Egresos</span><progress class="h-2 w-full accent-rose-600" max="{{ $maxFinanzasMes }}" value="{{ $month['egresos'] }}">{{ $month['egresos'] }}</progress><span>{{ $this->formatClp($month['egresos']) }}</span></div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    </div>

    <flux:card class="grid gap-5" wire:loading.class="opacity-60">
        <div><flux:heading size="lg">Actividad reciente</flux:heading><flux:text>Últimas actuaciones del período seleccionado.</flux:text></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-[#c5c6cd] text-sm dark:divide-slate-700">
                <thead class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500"><tr><th class="px-3 py-3">Fecha</th><th class="px-3 py-3">Causa</th><th class="px-3 py-3">Estado</th><th class="px-3 py-3">Descripción</th><th class="px-3 py-3">Usuario</th><th class="px-3 py-3"></th></tr></thead>
                <tbody class="divide-y divide-[#c5c6cd] dark:divide-slate-700">
                    @forelse ($data['actividadReciente'] as $actuacion)
                        <tr wire:key="actividad-{{ $actuacion->id }}"><td class="whitespace-nowrap px-3 py-4">{{ $actuacion->fecha?->format('d-m-Y') ?? '—' }}</td><td class="min-w-52 px-3 py-4"><span class="font-medium">{{ $actuacion->causa->numero_causa ?? 'Sin número' }}</span><br><span class="text-xs text-zinc-500">{{ $actuacion->causa->nombre }}</span></td><td class="px-3 py-4"><flux:badge color="blue" size="sm">{{ $actuacion->estadoProcesal?->nombre ?? 'Sin estado' }}</flux:badge></td><td class="min-w-64 px-3 py-4">{{ \Illuminate\Support\Str::limit($actuacion->descripcion, 100) }}</td><td class="whitespace-nowrap px-3 py-4">{{ $actuacion->creador?->name ?? 'Usuario no disponible' }}</td><td class="px-3 py-4"><flux:button size="sm" variant="ghost" icon="eye" :href="route('causas.show', $actuacion->causa)" wire:navigate>Ver</flux:button></td></tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-zinc-500">No existen actuaciones para los filtros seleccionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card class="grid gap-5" wire:loading.class="opacity-60">
        <div><flux:heading size="lg">Próximos recordatorios</flux:heading><flux:text>Agenda personal de los próximos plazos y tareas asignados.</flux:text></div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($this->proximosRecordatorios as $recordatorio)
                <a wire:key="dashboard-recordatorio-{{ $recordatorio->id }}" href="{{ route('causas.show', $recordatorio->causa) }}" wire:navigate class="rounded-sm border border-[#c5c6cd] p-4 transition hover:border-[#006a61] dark:border-slate-700 dark:hover:border-teal-400">
                    <div class="flex justify-between gap-3"><span class="font-semibold">{{ $recordatorio->fecha_hora->format('d-m-Y H:i') }}</span><flux:icon icon="bell" class="size-4 text-amber-600" /></div>
                    <p class="mt-2 text-sm font-medium">{{ $recordatorio->titulo }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $recordatorio->causa->numero_causa ?? 'Sin número' }} · {{ $recordatorio->causa->nombre }}</p>
                </a>
            @empty
                <flux:text>No tienes próximos recordatorios asignados.</flux:text>
            @endforelse
        </div>
    </flux:card>

    <flux:card class="grid gap-5" wire:loading.class="opacity-60">
        <div><flux:heading size="lg">Causas que requieren atención</flux:heading><flux:text>Control informativo de expedientes sin actuaciones registradas; no aplica un plazo jurídico automático.</flux:text></div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-sm border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"><span class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Sin actuaciones</span><p class="mt-1 text-2xl font-semibold">{{ $summary['sinActuaciones'] }}</p></div>
            <div class="rounded-sm border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"><span class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Sin responsable</span><p class="mt-1 text-2xl font-semibold">{{ $summary['sinResponsable'] }}</p></div>
            <div class="rounded-sm border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30"><span class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Sin estado procesal</span><p class="mt-1 text-2xl font-semibold">{{ $summary['sinEstadoProcesal'] }}</p></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($data['causasSinActuaciones'] as $causa)
                <a wire:key="atencion-causa-{{ $causa->id }}" href="{{ route('causas.show', $causa) }}" wire:navigate class="rounded-sm border border-[#c5c6cd] p-4 transition hover:border-[#006a61] dark:border-slate-700 dark:hover:border-teal-400"><div class="flex justify-between gap-3"><span class="font-semibold">{{ $causa->numero_causa ?? 'Sin número' }}</span><span class="text-xs text-zinc-500">{{ $causa->fecha_ingreso?->format('d-m-Y') ?? 'Sin fecha' }}</span></div><p class="mt-1 text-sm">{{ $causa->nombre }}</p><p class="mt-2 text-xs text-zinc-500">{{ $causa->responsable?->etiquetaResponsable() ?? 'Sin responsable' }}</p></a>
            @empty
                <flux:text>No existen causas sin actuaciones para los filtros seleccionados.</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
