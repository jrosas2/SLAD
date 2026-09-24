<?php

use App\Enums\EstadoImportacion;
use App\Enums\Permiso;
use App\Models\Importacion;
use App\Models\User;
use App\Services\Imports\HistoricalExcelAnalyzer;
use App\Services\Imports\HistoricalImportService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Importación histórica')] class extends Component
{
    use WithFileUploads;

    public $archivo;

    /** @var array<string, mixed> */
    public array $resumen = [];

    /** @var list<array<string, mixed>> */
    public array $previsualizacion = [];

    public bool $confirmacion = false;

    #[Locked]
    public ?int $importacionId = null;

    #[Locked]
    public bool $hashDuplicado = false;

    public function mount(): void
    {
        Gate::authorize(Permiso::ImportacionesVer->value);
    }

    public function analizar(HistoricalExcelAnalyzer $analyzer): void
    {
        Gate::authorize(Permiso::ImportacionesVer->value);

        $this->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:10240'],
        ], attributes: ['archivo' => 'archivo Excel']);

        $extension = Str::lower($this->archivo->getClientOriginalExtension());
        $storedPath = $this->archivo->storeAs('importaciones', Str::uuid().'.'.$extension, 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);
        $hash = hash_file('sha256', $absolutePath);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $importacion = Importacion::query()->create([
            'archivo' => Str::limit($this->archivo->getClientOriginalName(), 255, ''),
            'ruta_archivo' => $storedPath,
            'hash_archivo' => $hash,
            'user_id' => $user->id,
            'estado' => EstadoImportacion::Pendiente,
        ]);
        $this->importacionId = $importacion->id;
        Log::info('Archivo histórico cargado para análisis.', ['importacion_id' => $importacion->id, 'user_id' => $user->id, 'archivo' => $importacion->archivo]);

        try {
            $analysis = $analyzer->analyze($absolutePath);
            $this->hashDuplicado = Importacion::query()
                ->where('hash_archivo', $hash)
                ->whereKeyNot($importacion->id)
                ->whereIn('estado', [EstadoImportacion::Completada, EstadoImportacion::CompletadaConErrores])
                ->exists();

            foreach (array_chunk($analysis['issues'], 250) as $issues) {
                $importacion->errores()->createMany(array_map(fn (array $issue): array => [
                    'fila' => $issue['fila'],
                    'numero_causa' => $issue['numero_causa'],
                    'campo' => $issue['campo'],
                    'valor_original' => $issue['valor_original'],
                    'codigo_error' => $issue['codigo_error'],
                    'mensaje' => '['.$issue['nivel'].'] '.$issue['mensaje'],
                    'datos_originales' => $issue['datos_originales'],
                ], $issues));
            }

            $this->resumen = [
                ...$analysis['summary'],
                'hoja' => $analysis['sheet'],
                'encabezados' => $analysis['headers'],
                'hash_duplicado' => $this->hashDuplicado,
            ];
            $this->previsualizacion = $analysis['preview'];
            $this->confirmacion = false;
            $importacion->update([
                'estado' => EstadoImportacion::Analizada,
                'total_filas' => $analysis['summary']['filas_detectadas'],
                'filas_error' => $analysis['summary']['errores'],
                'resumen' => $this->resumen,
            ]);
            Log::info('Archivo histórico analizado.', ['importacion_id' => $importacion->id, 'user_id' => $user->id, 'filas' => $analysis['summary']['filas_detectadas']]);
            Flux::toast('Archivo analizado. Revisa la previsualización antes de confirmar.', variant: 'success');
        } catch (\Throwable $exception) {
            $importacion->update(['estado' => EstadoImportacion::Fallida, 'fecha_fin' => now()]);
            Storage::disk('local')->delete($importacion->ruta_archivo);
            $importacion->errores()->create(['codigo_error' => 'ARCHIVO_INVALIDO', 'mensaje' => 'No fue posible analizar el archivo. Verifica su formato y contenido.']);
            Log::warning('No fue posible analizar el archivo histórico.', [
                'importacion_id' => $importacion->id,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
            $this->addError('archivo', 'No fue posible analizar el archivo. Verifica su formato y contenido e inténtalo nuevamente.');
            $this->reset('resumen', 'previsualizacion', 'confirmacion');
        }

        unset($this->historial);
    }

    public function confirmarImportacion(HistoricalImportService $service): void
    {
        Gate::authorize(Permiso::ImportacionesEjecutar->value);

        if (! $this->confirmacion) {
            $this->addError('confirmacion', 'Debes confirmar explícitamente la importación.');

            return;
        }

        if ($this->hashDuplicado) {
            $this->addError('confirmacion', 'Este archivo ya fue importado anteriormente.');

            return;
        }

        $importacion = Importacion::query()->findOrFail($this->importacionId);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $result = $service->import($importacion, $user);
            $this->resumen = $result->resumen ?? [];
            $this->previsualizacion = [];
            $this->confirmacion = false;
            Flux::toast('Importación histórica completada.', variant: 'success');
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('confirmacion', $exception->getMessage());
        }

        unset($this->historial);
    }

    public function descargarErrores(int $importacionId): StreamedResponse
    {
        Gate::authorize(Permiso::ImportacionesVer->value);
        $importacion = Importacion::query()->with('errores')->findOrFail($importacionId);

        return response()->streamDownload(function () use ($importacion): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fputcsv($stream, ['fila', 'numero_causa', 'campo', 'valor', 'error'], ';');

            foreach ($importacion->errores as $error) {
                fputcsv($stream, [$error->fila, $error->numero_causa, $error->campo, $error->valor_original, $error->mensaje], ';');
            }

            fclose($stream);
        }, 'errores-importacion-'.$importacion->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Collection<int, Importacion> */
    #[Computed]
    public function historial(): Collection
    {
        return Importacion::query()
            ->select(['id', 'archivo', 'user_id', 'estado', 'total_filas', 'filas_importadas', 'filas_omitidas', 'filas_error', 'created_at'])
            ->with('usuario:id,name')
            ->withCount('errores')
            ->latest()
            ->limit(20)
            ->get();
    }

    public function colorEstado(EstadoImportacion $estado): string
    {
        return match ($estado) {
            EstadoImportacion::Completada => 'green',
            EstadoImportacion::CompletadaConErrores => 'amber',
            EstadoImportacion::Fallida => 'red',
            EstadoImportacion::Importando => 'blue',
            default => 'zinc',
        };
    }
};
?>

<div class="grid gap-6 p-4 sm:p-6 lg:p-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="grid gap-1">
            <flux:heading size="xl">Importación histórica</flux:heading>
            <flux:text>Carga el Excel anterior, revisa el análisis y confirma solo cuando los datos sean correctos.</flux:text>
        </div>
        <flux:badge color="blue">Importación inicial desde Excel</flux:badge>
    </div>

    <flux:card class="grid gap-5">
        <div class="grid gap-1">
            <flux:heading size="lg">1. Seleccionar y analizar</flux:heading>
            <flux:text>Archivos .xlsx o .xls de hasta 10 MB. Seleccionar el archivo nunca importa datos automáticamente.</flux:text>
        </div>
        <form wire:submit="analizar" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <flux:field>
                <flux:label>Archivo Excel histórico</flux:label>
                <flux:input type="file" wire:model="archivo" accept=".xlsx,.xls" />
                <flux:error name="archivo" />
            </flux:field>
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="analizar,archivo">Analizar Excel</span>
                <span wire:loading wire:target="analizar,archivo">Analizando…</span>
            </flux:button>
        </form>
    </flux:card>

    @if ($resumen !== [])
        <flux:card class="grid gap-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><flux:heading size="lg">2. Resumen previo</flux:heading><flux:text>Hoja detectada: {{ $resumen['hoja'] ?? '—' }}</flux:text></div>
                <flux:badge :color="$hashDuplicado ? 'red' : 'green'">{{ $hashDuplicado ? 'Archivo ya importado' : 'Solo previsualización' }}</flux:badge>
            </div>
            @if ($hashDuplicado)
                <flux:callout variant="danger" icon="exclamation-triangle" text="Ya existe una importación completada con el mismo SHA-256. La confirmación está bloqueada para evitar duplicados." />
            @endif
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    'Filas detectadas' => $resumen['filas_detectadas'] ?? 0, 'Filas válidas' => $resumen['filas_validas'] ?? 0,
                    'Advertencias' => $resumen['advertencias'] ?? 0, 'Errores' => $resumen['errores'] ?? 0,
                    'Posibles duplicados' => $resumen['posibles_duplicados'] ?? 0, 'Causas nuevas' => $resumen['causas_nuevas'] ?? 0,
                    'Materias nuevas' => count($resumen['materias_nuevas'] ?? []), 'Submaterias nuevas' => count($resumen['submaterias_nuevas'] ?? []),
                    'Responsables reconocidos' => $resumen['responsables_reconocidos'] ?? 0, 'Actuaciones detectadas' => $resumen['actuaciones_detectadas'] ?? 0,
                    'Ingresos detectados' => $resumen['ingresos_detectados'] ?? 0, 'Egresos detectados' => $resumen['egresos_detectados'] ?? 0,
                ] as $label => $value)
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50">
                        <div class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
            @if (($resumen['responsables_no_encontrados'] ?? []) !== [])
                <flux:callout variant="warning" icon="users" heading="Responsables no encontrados">{{ implode(', ', $resumen['responsables_no_encontrados']) }}. No se crearán usuarios y esas causas quedarán sin responsable.</flux:callout>
            @endif
            @if (($resumen['ciudades_no_encontradas'] ?? []) !== [])
                <flux:callout variant="warning" icon="map-pin" heading="Ciudades que requieren revisión">{{ implode(', ', $resumen['ciudades_no_encontradas']) }}. No se inventarán ciudades ni juzgados.</flux:callout>
            @endif
        </flux:card>

        <flux:card class="grid gap-5 overflow-hidden">
            <div><flux:heading size="lg">3. Previsualización</flux:heading><flux:text>Se muestran hasta 100 filas. Ninguna causa ha sido persistida en esta etapa.</flux:text></div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-[1200px] divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900"><tr>
                        @foreach (['Fila', 'N.º causa', 'Nombre', 'Materia', 'Submateria', 'Responsable', 'Estado', 'Monto', 'Actuaciones', 'Ingreso', 'Egreso', 'Resultado'] as $heading)<th class="px-4 py-3 font-semibold">{{ $heading }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                        @forelse ($previsualizacion as $row)
                            <tr wire:key="preview-{{ $row['fila'] }}" class="align-top">
                                <td class="px-4 py-3">{{ $row['fila'] }}</td><td class="px-4 py-3 font-medium">{{ $row['numero_causa'] ?? '—' }}</td>
                                <td class="max-w-64 px-4 py-3">{{ $row['nombre'] ?? '—' }}</td><td class="px-4 py-3">{{ $row['materia'] ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $row['submateria'] ?? '—' }}</td><td class="px-4 py-3">{{ $row['responsable'] ?? '—' }}</td><td class="px-4 py-3">{{ $row['estado'] ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $row['monto_demandado'] === null ? '—' : '$ '.number_format($row['monto_demandado'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-center">{{ $row['actuaciones_detectadas'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $row['ingreso'] === null ? '—' : '$ '.number_format($row['ingreso'], 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $row['egreso'] === null ? '—' : '$ '.number_format($row['egreso'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3"><flux:badge :color="match ($row['resultado']) { 'VÁLIDO' => 'green', 'ERROR' => 'red', 'DUPLICADO', 'POSIBLE DUPLICADO' => 'amber', default => 'blue' }">{{ $row['resultado'] }}</flux:badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="px-4 py-10 text-center text-zinc-500">No se detectaron filas de datos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        @can(\App\Enums\Permiso::ImportacionesEjecutar->value)
            <flux:card class="grid gap-5">
                <div><flux:heading size="lg">4. Confirmar importación</flux:heading><flux:text>Las filas con error o posibles duplicados serán omitidas. Las materias y submaterias nuevas se crearán al importar.</flux:text></div>
                <flux:checkbox wire:model="confirmacion" label="He revisado la previsualización y confirmo la importación definitiva." />
                <flux:error name="confirmacion" />
                <div class="flex justify-end"><flux:button variant="primary" icon="arrow-up-tray" wire:click="confirmarImportacion" :disabled="$hashDuplicado" wire:loading.attr="disabled">Importar datos</flux:button></div>
            </flux:card>
        @endcan
    @endif

    <flux:card class="grid gap-5">
        <div><flux:heading size="lg">Historial de importaciones</flux:heading><flux:text>Últimos 20 análisis y ejecuciones registrados.</flux:text></div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-[900px] divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-900"><tr><th class="px-4 py-3">Archivo</th><th class="px-4 py-3">Usuario</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Filas</th><th class="px-4 py-3">Importadas</th><th class="px-4 py-3">Omitidas</th><th class="px-4 py-3">Fecha</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                    @forelse ($this->historial as $item)
                        <tr wire:key="importacion-{{ $item->id }}">
                            <td class="max-w-72 truncate px-4 py-3 font-medium">{{ $item->archivo }}</td><td class="px-4 py-3">{{ $item->usuario?->name ?? 'Usuario eliminado' }}</td>
                            <td class="px-4 py-3"><flux:badge :color="$this->colorEstado($item->estado)">{{ str_replace('_', ' ', $item->estado->value) }}</flux:badge></td>
                            <td class="px-4 py-3">{{ $item->total_filas }}</td><td class="px-4 py-3">{{ $item->filas_importadas }}</td><td class="px-4 py-3">{{ $item->filas_omitidas }}</td><td class="whitespace-nowrap px-4 py-3">{{ $item->created_at?->format('d-m-Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">@if ($item->errores_count > 0)<flux:button size="sm" variant="ghost" icon="arrow-down-tray" wire:click="descargarErrores({{ $item->id }})">Errores CSV</flux:button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-zinc-500">Aún no hay importaciones registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
