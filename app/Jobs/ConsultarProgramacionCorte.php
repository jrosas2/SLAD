<?php

namespace App\Jobs;

use App\Models\PjudProgramacion;
use App\Models\TribunalPjud;
use App\Services\Pjud\DetectorProgramacionPjud;
use App\Services\Pjud\FuenteProgramacionSalas;
use App\Support\Pjud\RegistroMonitoreoPjud;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta la programación de salas (tablas) de una Corte y registra las
 * causas de SLAD que aparezcan. Cada programación nueva dispara su aviso en
 * Google Calendar, con el evento el día de la vista.
 */
class ConsultarProgramacionCorte implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [120, 600];

    // Una Corte grande (Santiago) puede tener más de 100 salas-día con tabla.
    public int $timeout = 900;

    public function __construct(public int $tribunalCodigo) {}

    public function handle(FuenteProgramacionSalas $fuente, DetectorProgramacionPjud $detector): void
    {
        $corte = TribunalPjud::query()->find($this->tribunalCodigo);

        if ($corte === null) {
            return;
        }

        $hoy = CarbonImmutable::today();
        $entradas = $fuente->obtener($corte, $hoy);
        $nuevas = $detector->registrar($corte, $entradas);

        foreach ($nuevas as $programacion) {
            NotificarProgramacionPjudGoogleCalendar::dispatch($programacion->id);
        }

        $reenviados = $this->reenviarAvisosPendientes($corte, $hoy, array_map(fn (PjudProgramacion $p): int => $p->id, $nuevas));

        RegistroMonitoreoPjud::sumar('consultas_ok', 1, RegistroMonitoreoPjud::PROGRAMACION);

        Log::info('Programación de salas PJUD revisada.', [
            'corte' => $corte->codigo,
            'causas_en_tabla' => count($entradas),
            'programaciones_nuevas' => count($nuevas),
            'avisos_reenviados' => $reenviados,
        ]);
    }

    /**
     * Reintenta los avisos que quedaron sin enviar en una revisión anterior,
     * sólo de vistas que aún no pasan.
     *
     * @param  list<int>  $excluir
     */
    private function reenviarAvisosPendientes(TribunalPjud $corte, CarbonImmutable $hoy, array $excluir): int
    {
        $minutos = max(1, (int) config('pjud.programacion.notificacion.reintentar_pendientes_minutos', 30));

        $ids = PjudProgramacion::query()
            ->where('tribunal_pjud_codigo', $corte->codigo)
            ->whereDate('fecha_vista', '>=', $hoy->toDateString())
            ->whereNull('notificado_at')
            ->where('updated_at', '<=', now()->subMinutes($minutos))
            ->whereNotIn('id', $excluir)
            ->pluck('id');

        foreach ($ids as $id) {
            NotificarProgramacionPjudGoogleCalendar::dispatch((int) $id);
        }

        return $ids->count();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("pjud-programacion-{$this->tribunalCodigo}"))->releaseAfter(60)->expireAfter(1200)];
    }

    public function failed(?Throwable $exception): void
    {
        RegistroMonitoreoPjud::sumar('consultas_error', 1, RegistroMonitoreoPjud::PROGRAMACION);
        RegistroMonitoreoPjud::anotarError($this->tribunalCodigo, RegistroMonitoreoPjud::PROGRAMACION);

        Log::error('No fue posible consultar la programación de salas del PJUD.', [
            'corte' => $this->tribunalCodigo,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
