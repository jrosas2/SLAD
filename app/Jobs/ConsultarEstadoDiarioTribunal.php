<?php

namespace App\Jobs;

use App\Models\TribunalPjud;
use App\Services\Pjud\DetectorMovimientosPjud;
use App\Services\Pjud\FuenteEstadoDiario;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta el Estado Diario de un tribunal y registra los movimientos de las
 * causas de SLAD que aparezcan. Cada movimiento nuevo dispara su aviso en
 * Google Calendar.
 */
class ConsultarEstadoDiarioTribunal implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct(public int $tribunalCodigo, public string $fecha) {}

    public function handle(FuenteEstadoDiario $fuente, DetectorMovimientosPjud $detector): void
    {
        $tribunal = TribunalPjud::query()->find($this->tribunalCodigo);

        if ($tribunal === null) {
            return;
        }

        $fecha = CarbonImmutable::parse($this->fecha);
        $entradas = $fuente->obtener($tribunal, $fecha);
        $nuevos = $detector->registrar($tribunal, $fecha, $entradas);

        foreach ($nuevos as [$movimiento, $entrada]) {
            // Detalle opcional (Tipo, Estado, Materia…): sólo para causas de SLAD.
            $detalle = $fuente->detalle($tribunal, $fecha, $entrada);
            if ($detalle !== null) {
                $movimiento->forceFill(['detalle' => $detalle])->save();
            }

            NotificarMovimientoPjudGoogleCalendar::dispatch($movimiento->id);
        }

        Log::info('Estado Diario PJUD revisado.', [
            'tribunal' => $tribunal->codigo,
            'fecha' => $fecha->toDateString(),
            'filas_listado' => count($entradas),
            'movimientos_nuevos' => count($nuevos),
        ]);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pjud-estado-diario-{$this->tribunalCodigo}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No fue posible consultar el Estado Diario del PJUD.', [
            'tribunal' => $this->tribunalCodigo,
            'fecha' => $this->fecha,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
