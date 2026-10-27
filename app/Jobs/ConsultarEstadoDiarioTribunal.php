<?php

namespace App\Jobs;

use App\Models\PjudMovimiento;
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

        // Reintenta los avisos que quedaron pendientes en una revisión anterior
        // (job interrumpido, Google Calendar sin conexión, etc.). Sólo los que
        // llevan un tiempo sin notificar, para no duplicar un aviso que ya está en cola.
        $pendientes = $this->reenviarAvisosPendientes($tribunal, $fecha, array_map(
            fn (array $nuevo): int => $nuevo[0]->id,
            $nuevos,
        ));

        Log::info('Estado Diario PJUD revisado.', [
            'tribunal' => $tribunal->codigo,
            'fecha' => $fecha->toDateString(),
            'filas_listado' => count($entradas),
            'movimientos_nuevos' => count($nuevos),
            'avisos_reenviados' => $pendientes,
        ]);
    }

    /**
     * @param  list<int>  $excluir  Movimientos recién creados (ya se despachó su aviso).
     */
    private function reenviarAvisosPendientes(TribunalPjud $tribunal, CarbonImmutable $fecha, array $excluir): int
    {
        $minutos = max(1, (int) config('pjud.notificacion.reintentar_pendientes_minutos', 30));

        $ids = PjudMovimiento::query()
            ->where('tribunal_pjud_codigo', $tribunal->codigo)
            ->whereDate('fecha_estado_diario', $fecha->toDateString())
            ->whereNull('notificado_at')
            ->where('updated_at', '<=', now()->subMinutes($minutos))
            ->whereNotIn('id', $excluir)
            ->pluck('id');

        foreach ($ids as $id) {
            NotificarMovimientoPjudGoogleCalendar::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * Un bloqueo por tribunal Y fecha: las consultas de "hoy" y "ayer" del mismo
     * tribunal no se bloquean entre sí (antes se liberaban y gastaban intentos).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pjud-estado-diario-{$this->tribunalCodigo}-{$this->fecha}"))->releaseAfter(30)->expireAfter(300)];
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
