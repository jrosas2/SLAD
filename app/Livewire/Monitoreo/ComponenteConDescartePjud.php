<?php

namespace App\Livewire\Monitoreo;

use App\Enums\Permiso;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\User;
use App\Services\Pjud\DescartadorRegistrosPjud;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Base de las pantallas que permiten eliminar (descartar) registros del
 * monitoreo PJUD que resultaron ser falsos positivos: ventana de confirmación
 * con motivo obligatorio. La usan Monitoreo › Causas, Monitoreo › Salas y el
 * detalle de la causa.
 */
abstract class ComponenteConDescartePjud extends Component
{
    public bool $showDescarte = false;

    #[Locked]
    public ?string $descarteTipo = null;

    #[Locked]
    public ?int $descarteId = null;

    public string $motivoDescarte = '';

    #[Computed]
    public function puedeDescartarPjud(): bool
    {
        return (bool) auth()->user()?->can(Permiso::MonitoreoEliminar->value);
    }

    public function confirmarDescarte(string $tipo, int $id): void
    {
        abort_unless($this->puedeDescartarPjud(), 403);

        $registro = $this->registroDescartable($tipo, $id);

        $this->descarteTipo = $tipo;
        $this->descarteId = $registro->id;
        $this->motivoDescarte = '';
        $this->resetValidation('motivoDescarte');
        unset($this->registroADescartar);
        $this->showDescarte = true;
    }

    #[Computed]
    public function registroADescartar(): PjudMovimiento|PjudProgramacion|null
    {
        if ($this->descarteTipo === null || $this->descarteId === null) {
            return null;
        }

        $registro = $this->consultaDescartable($this->descarteTipo)
            ->with('causa:id,nombre,numero_causa')
            ->find($this->descarteId);

        return $registro instanceof PjudMovimiento || $registro instanceof PjudProgramacion ? $registro : null;
    }

    public function descartar(DescartadorRegistrosPjud $descartador): void
    {
        abort_unless($this->puedeDescartarPjud(), 403);

        $this->validate(
            ['motivoDescarte' => ['required', 'string', 'min:5', 'max:500']],
            [],
            ['motivoDescarte' => 'motivo'],
        );

        $registro = $this->registroDescartable((string) $this->descarteTipo, (int) $this->descarteId);
        $eventoEliminado = $descartador->descartar($registro, $this->usuarioActual(), $this->motivoDescarte);

        $this->cancelarDescarte();

        Flux::toast(
            variant: $eventoEliminado ? 'success' : 'warning',
            text: $eventoEliminado
                ? 'Registro del monitoreo eliminado y registrado en la bitácora de auditoría.'
                : 'Registro eliminado, pero no se pudo borrar su evento en Google Calendar: elimínelo manualmente.',
        );

        $this->despuesDeDescartar();
    }

    public function cancelarDescarte(): void
    {
        $this->reset('showDescarte', 'descarteTipo', 'descarteId', 'motivoDescarte');
        $this->resetValidation('motivoDescarte');
        unset($this->registroADescartar);
    }

    /**
     * Registros que la pantalla permite descartar (por defecto, los que el
     * usuario puede ver en el monitoreo).
     *
     * @param  Builder<PjudMovimiento>|Builder<PjudProgramacion>  $query
     * @return Builder<PjudMovimiento>|Builder<PjudProgramacion>
     */
    protected function limitarRegistrosDescartables(Builder $query): Builder
    {
        return $query->visibleFor($this->usuarioActual());
    }

    /** Refresca los datos de la pantalla después de descartar. */
    abstract protected function despuesDeDescartar(): void;

    protected function usuarioActual(): User
    {
        $usuario = auth()->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }

    private function registroDescartable(string $tipo, int $id): PjudMovimiento|PjudProgramacion
    {
        $registro = $this->consultaDescartable($tipo)->find($id);
        abort_if($registro === null, 404);

        return $registro;
    }

    /** @return Builder<PjudMovimiento>|Builder<PjudProgramacion> */
    private function consultaDescartable(string $tipo): Builder
    {
        return match ($tipo) {
            'movimiento' => $this->limitarRegistrosDescartables(PjudMovimiento::query()),
            'programacion' => $this->limitarRegistrosDescartables(PjudProgramacion::query()),
            default => abort(404),
        };
    }
}
