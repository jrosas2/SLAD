<?php

use App\Models\Accion;
use App\Models\Causa;
use App\Models\Ciudad;
use App\Models\Direccion;
use App\Models\EstadoCausa;
use App\Models\EstadoProcesal;
use App\Models\Juzgado;
use App\Models\Materia;
use App\Models\Submateria;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Formulario de causa')] class extends Component {
    private ?Causa $loadedCausa = null;

    #[Locked]
    public ?int $causaId = null;

    public string $nombre = '';

    public string $numeroCausa = '';

    public string $fechaCausa = '';

    public string $fechaIngreso = '';

    public string $ciudadId = '';

    public string $juzgadoId = '';

    public string $materiaId = '';

    public string $submateriaId = '';

    public string $accionId = '';

    public string $estadoProcesalId = '';

    public string $estadoCausaId = '';

    public string $direccionId = '';

    public string $demandanteDemandado = '';

    public string $responsableId = '';

    public string $montoDemandado = '';

    public bool $tieneCotizaciones = false;

    public string $observacionImportante = '';

    public function mount(?Causa $causa = null): void
    {
        if ($causa?->exists) {
            Gate::authorize('update', $causa);
            $causa->loadMissing('juzgado:id,ciudad_id');
            $this->fillFromCausa($causa);

            return;
        }

        Gate::authorize('create', Causa::class);
    }

    public function updatedMateriaId(): void
    {
        if ($this->submateriaId !== '' && ! Submateria::query()
            ->whereKey($this->submateriaId)
            ->where('materia_id', $this->materiaId)
            ->exists()) {
            $this->submateriaId = '';
        }

        unset($this->submaterias);
    }

    public function updatedCiudadId(): void
    {
        if ($this->juzgadoId !== '' && ! Juzgado::query()
            ->whereKey($this->juzgadoId)
            ->where('ciudad_id', $this->ciudadId)
            ->exists()) {
            $this->juzgadoId = '';
        }

        unset($this->juzgados);
    }

    public function save(): void
    {
        $causa = $this->causaId === null ? null : Causa::query()->findOrFail($this->causaId);
        Gate::authorize($causa === null ? 'create' : 'update', $causa ?? Causa::class);

        $this->nombre = Str::squish($this->nombre);
        $this->numeroCausa = Str::squish($this->numeroCausa);
        $this->demandanteDemandado = Str::squish($this->demandanteDemandado);
        $this->observacionImportante = trim($this->observacionImportante);

        $rules = [
            'nombre' => ['required', 'string', 'max:255'],
            'numeroCausa' => ['nullable', 'string', 'max:100'],
            'fechaCausa' => ['nullable', 'date'],
            'fechaIngreso' => ['nullable', 'date'],
            'ciudadId' => ['nullable', 'required_with:juzgadoId', 'integer', $this->activeCatalogRule(Ciudad::class, $this->currentCiudadId())],
            'juzgadoId' => [
                'nullable',
                'required_with:ciudadId',
                'integer',
                Rule::exists(Juzgado::class, 'id')->where(function (QueryBuilder $query): void {
                    $query->where('ciudad_id', $this->ciudadId)
                        ->where(function (QueryBuilder $query): void {
                            $query->where('activo', true);

                            if ($this->currentCausa()?->juzgado_id !== null) {
                                $query->orWhere('id', $this->currentCausa()->juzgado_id);
                            }
                        });
                }),
            ],
            'materiaId' => ['required', 'integer', $this->activeCatalogRule(Materia::class, $this->currentCausa()?->materia_id)],
            'submateriaId' => [
                'nullable',
                'integer',
                Rule::exists(Submateria::class, 'id')->where(function (QueryBuilder $query): void {
                    $query->where('materia_id', $this->materiaId)
                        ->where(function (QueryBuilder $query): void {
                            $query->where('activo', true);

                            if ($this->currentCausa()?->submateria_id !== null) {
                                $query->orWhere('id', $this->currentCausa()->submateria_id);
                            }
                        });
                }),
            ],
            'accionId' => ['nullable', 'integer', $this->activeCatalogRule(Accion::class, $this->currentCausa()?->accion_id)],
            'estadoProcesalId' => ['nullable', 'integer', $this->activeCatalogRule(EstadoProcesal::class, $this->currentCausa()?->estado_procesal_id)],
            'estadoCausaId' => ['nullable', 'integer', $this->activeCatalogRule(EstadoCausa::class, $this->currentCausa()?->estado_causa_id)],
            'direccionId' => ['nullable', 'integer', $this->activeCatalogRule(Direccion::class, $this->currentCausa()?->direccion_id)],
            'demandanteDemandado' => ['nullable', 'string', 'max:255'],
            'montoDemandado' => ['nullable', 'integer', 'min:0', 'max:9999999999999'],
            'tieneCotizaciones' => ['required', 'boolean'],
            'observacionImportante' => ['nullable', 'string', 'max:10000'],
        ];

        if ($this->canAssignResponsible) {
            $rules['responsableId'] = [
                'nullable',
                'integer',
                Rule::in(User::query()->asignableComoResponsable()->pluck('id')->all()),
            ];
        }

        $validated = $this->validate($rules, attributes: [
            'numeroCausa' => 'número de causa',
            'fechaCausa' => 'fecha de causa',
            'fechaIngreso' => 'fecha de ingreso',
            'ciudadId' => 'ciudad',
            'juzgadoId' => 'juzgado',
            'materiaId' => 'materia',
            'submateriaId' => 'submateria',
            'accionId' => 'acción',
            'estadoProcesalId' => 'estado procesal',
            'estadoCausaId' => 'estado de causa',
            'direccionId' => 'dirección',
            'demandanteDemandado' => 'demandante o demandado',
            'responsableId' => 'responsable',
            'montoDemandado' => 'monto demandado',
            'tieneCotizaciones' => 'cotizaciones',
            'observacionImportante' => 'observación importante',
        ]);

        $attributes = [
            'nombre' => $validated['nombre'],
            'numero_causa' => $this->nullableString($validated['numeroCausa']),
            'fecha_causa' => $this->nullableString($validated['fechaCausa']),
            'fecha_ingreso' => $this->nullableString($validated['fechaIngreso']),
            'juzgado_id' => $this->nullableId($validated['juzgadoId']),
            'materia_id' => (int) $validated['materiaId'],
            'submateria_id' => $this->nullableId($validated['submateriaId']),
            'accion_id' => $this->nullableId($validated['accionId']),
            'estado_procesal_id' => $this->nullableId($validated['estadoProcesalId']),
            'estado_causa_id' => $this->nullableId($validated['estadoCausaId']),
            'direccion_id' => $this->nullableId($validated['direccionId']),
            'demandante_demandado' => $this->nullableString($validated['demandanteDemandado']),
            'monto_demandado' => $this->nullableString($validated['montoDemandado']),
            'tiene_cotizaciones' => $validated['tieneCotizaciones'],
            'observacion_importante' => $this->nullableString($validated['observacionImportante']),
        ];

        if ($causa === null) {
            $causa = Causa::query()->create($attributes);
            $message = 'Causa creada correctamente.';
        } else {
            $causa->update($attributes);
            $message = 'Causa actualizada correctamente.';
        }

        if ($this->canAssignResponsible) {
            $responsableId = $this->nullableId($validated['responsableId']);

            if ($responsableId === null) {
                $causa->responsable()->dissociate();
            } else {
                $causa->responsable()->associate($responsableId);
            }

            $causa->save();
        }

        session()->flash('status', $message);
        $this->redirectRoute('causas.show', ['causa' => $causa->id], navigate: true);
    }

    /** @return Collection<int, Ciudad> */
    #[Computed]
    public function ciudades(): Collection
    {
        return $this->activeOptions(Ciudad::query(), $this->currentCiudadId())
            ->select(['id', 'nombre', 'activo'])
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, Juzgado> */
    #[Computed]
    public function juzgados(): Collection
    {
        if ($this->ciudadId === '') {
            return new Collection;
        }

        return $this->activeOptions(Juzgado::query(), $this->currentCausa()?->juzgado_id)
            ->select(['id', 'ciudad_id', 'nombre', 'activo'])
            ->where('ciudad_id', $this->ciudadId)
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, Materia> */
    #[Computed]
    public function materias(): Collection
    {
        return $this->activeOptions(Materia::query(), $this->currentCausa()?->materia_id)
            ->select(['id', 'nombre', 'activo'])
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, Submateria> */
    #[Computed]
    public function submaterias(): Collection
    {
        if ($this->materiaId === '') {
            return new Collection;
        }

        return $this->activeOptions(Submateria::query(), $this->currentCausa()?->submateria_id)
            ->select(['id', 'materia_id', 'nombre', 'activo'])
            ->where('materia_id', $this->materiaId)
            ->orderBy('nombre')
            ->get();
    }

    /** @return Collection<int, Accion> */
    #[Computed]
    public function acciones(): Collection
    {
        return $this->activeOptions(Accion::query(), $this->currentCausa()?->accion_id)
            ->select(['id', 'nombre', 'activo'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, EstadoProcesal> */
    #[Computed]
    public function estadosProcesales(): Collection
    {
        return $this->activeOptions(EstadoProcesal::query(), $this->currentCausa()?->estado_procesal_id)
            ->select(['id', 'nombre', 'activo'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, EstadoCausa> */
    #[Computed]
    public function estadosCausa(): Collection
    {
        return $this->activeOptions(EstadoCausa::query(), $this->currentCausa()?->estado_causa_id)
            ->select(['id', 'nombre', 'activo'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, Direccion> */
    #[Computed]
    public function direcciones(): Collection
    {
        return $this->activeOptions(Direccion::query(), $this->currentCausa()?->direccion_id)
            ->select(['id', 'nombre', 'activo'])->orderBy('nombre')->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function responsables(): Collection
    {
        return User::query()
            ->asignableComoResponsable()
            ->select(['id', 'codigo', 'name', 'activo'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canAssignResponsible(): bool
    {
        return Gate::allows('assignResponsible', $this->currentCausa() ?? new Causa);
    }

    private function fillFromCausa(Causa $causa): void
    {
        $this->loadedCausa = $causa;
        $this->causaId = $causa->id;
        $this->nombre = $causa->nombre;
        $this->numeroCausa = $causa->numero_causa ?? '';
        $this->fechaCausa = $causa->fecha_causa?->format('Y-m-d') ?? '';
        $this->fechaIngreso = $causa->fecha_ingreso?->format('Y-m-d') ?? '';
        $this->ciudadId = $causa->juzgado === null ? '' : (string) $causa->juzgado->ciudad_id;
        $this->juzgadoId = $causa->juzgado_id === null ? '' : (string) $causa->juzgado_id;
        $this->materiaId = (string) $causa->materia_id;
        $this->submateriaId = $causa->submateria_id === null ? '' : (string) $causa->submateria_id;
        $this->accionId = $causa->accion_id === null ? '' : (string) $causa->accion_id;
        $this->estadoProcesalId = $causa->estado_procesal_id === null ? '' : (string) $causa->estado_procesal_id;
        $this->estadoCausaId = $causa->estado_causa_id === null ? '' : (string) $causa->estado_causa_id;
        $this->direccionId = $causa->direccion_id === null ? '' : (string) $causa->direccion_id;
        $this->demandanteDemandado = $causa->demandante_demandado ?? '';
        $this->responsableId = $causa->responsable_id === null ? '' : (string) $causa->responsable_id;
        $this->montoDemandado = $causa->monto_demandado === null
            ? ''
            : number_format((float) $causa->monto_demandado, 0, '.', '');
        $this->tieneCotizaciones = $causa->tiene_cotizaciones ?? false;
        $this->observacionImportante = $causa->observacion_importante ?? '';
    }

    private function currentCausa(): ?Causa
    {
        if ($this->causaId === null) {
            return null;
        }

        return $this->loadedCausa ??= Causa::query()
            ->with('juzgado:id,ciudad_id')
            ->find($this->causaId);
    }

    private function currentCiudadId(): ?int
    {
        return $this->currentCausa()?->juzgado?->ciudad_id;
    }

    private function activeOptions(Builder $query, ?int $currentId): Builder
    {
        return $query->where(function (Builder $query) use ($currentId): void {
            $query->where('activo', true);

            if ($currentId !== null) {
                $query->orWhere('id', $currentId);
            }
        });
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private function activeCatalogRule(string $model, ?int $currentId): Exists
    {
        return Rule::exists($model, 'id')->where(function (QueryBuilder $query) use ($currentId): void {
            $query->where('activo', true);

            if ($currentId !== null) {
                $query->orWhere('id', $currentId);
            }
        });
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}; ?>

<form wire:submit="save" class="flex w-full flex-1 flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 border-b border-[#c5c6cd] pb-6 sm:flex-row sm:items-end dark:border-slate-700">
        <div class="grid gap-2">
            <flux:badge color="blue" size="sm" class="w-fit">Gestión jurídica</flux:badge>
            <flux:heading size="xl" level="1">{{ $causaId === null ? 'Nueva causa' : 'Editar causa' }}</flux:heading>
            <flux:text>Completa los antecedentes principales del expediente.</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button variant="ghost" :href="$causaId === null ? route('causas.index') : route('causas.show', $causaId)" wire:navigate>Cancelar</flux:button>
            <flux:button variant="primary" type="submit" icon="check" wire:loading.attr="disabled">Guardar causa</flux:button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Identificación</flux:heading><flux:text>Datos que permiten reconocer la causa.</flux:text></div>
            <flux:input wire:model="nombre" label="Nombre" maxlength="255" required />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="numeroCausa" label="Número de causa" maxlength="100" />
                <flux:input wire:model="fechaCausa" type="date" label="Fecha de causa" />
                <flux:input wire:model="fechaIngreso" type="date" label="Fecha de ingreso" />
            </div>
        </flux:card>

        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Tribunal</flux:heading><flux:text>La ciudad se utiliza para limitar los juzgados disponibles.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="ciudadId" label="Ciudad">
                    <option value="">Sin ciudad</option>
                    @foreach ($this->ciudades as $ciudad)<option value="{{ $ciudad->id }}">{{ $ciudad->nombre }}{{ $ciudad->activo ? '' : ' (inactiva)' }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="juzgadoId" label="Juzgado" :disabled="$ciudadId === ''">
                    <option value="">Sin juzgado</option>
                    @foreach ($this->juzgados as $juzgado)<option value="{{ $juzgado->id }}">{{ $juzgado->nombre }}{{ $juzgado->activo ? '' : ' (inactivo)' }}</option>@endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Clasificación</flux:heading><flux:text>Materia, submateria, acción jurídica y partes procesales.</flux:text></div>
            <flux:select wire:model="direccionId" label="Dirección">
                <option value="">Sin dirección</option>
                @foreach ($this->direcciones as $direccion)<option value="{{ $direccion->id }}">{{ $direccion->nombre }}{{ $direccion->activo ? '' : ' (inactiva)' }}</option>@endforeach
            </flux:select>
            <flux:select wire:model.live="materiaId" label="Materia" required>
                <option value="">Selecciona una materia</option>
                @foreach ($this->materias as $materia)<option value="{{ $materia->id }}">{{ $materia->nombre }}{{ $materia->activo ? '' : ' (inactiva)' }}</option>@endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="submateriaId" label="Submateria" :disabled="$materiaId === ''">
                    <option value="">Sin submateria</option>
                    @foreach ($this->submaterias as $submateria)<option value="{{ $submateria->id }}">{{ $submateria->nombre }}{{ $submateria->activo ? '' : ' (inactiva)' }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="accionId" label="Acción">
                    <option value="">Sin acción</option>
                    @foreach ($this->acciones as $accion)<option value="{{ $accion->id }}">{{ $accion->nombre }}{{ $accion->activo ? '' : ' (inactiva)' }}</option>@endforeach
                </flux:select>
            </div>
            <flux:input wire:model="demandanteDemandado" label="Demandante / demandado" maxlength="255" />
        </flux:card>

        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Estado</flux:heading><flux:text>Situación procesal y estado general de la causa.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="estadoProcesalId" label="Estado procesal">
                    <option value="">Sin estado</option>
                    @foreach ($this->estadosProcesales as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}{{ $estado->activo ? '' : ' (inactivo)' }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="estadoCausaId" label="Estado de causa">
                    <option value="">Sin estado</option>
                    @foreach ($this->estadosCausa as $estado)<option value="{{ $estado->id }}">{{ $estado->nombre }}{{ $estado->activo ? '' : ' (inactivo)' }}</option>@endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Responsable</flux:heading><flux:text>Profesional asignado a la gestión del expediente.</flux:text></div>
            @if ($this->canAssignResponsible)
                <flux:select wire:model="responsableId" label="Responsable">
                    <option value="">Sin responsable</option>
                    @foreach ($this->responsables as $responsable)
                        <option value="{{ $responsable->id }}">{{ $responsable->etiquetaResponsable() }}</option>
                    @endforeach
                </flux:select>
            @else
                <div class="rounded-sm border border-[#c5c6cd] bg-zinc-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-900/50">
                    <span class="block text-xs font-medium text-zinc-500">Responsable</span>
                    <span data-testid="responsable-readonly" class="mt-1 block text-sm">{{ $this->currentCausa()?->responsable?->etiquetaResponsable() ?? 'Sin responsable' }}</span>
                </div>
            @endif
        </flux:card>

        <flux:card class="grid gap-5">
            <div><flux:heading size="lg">Información económica</flux:heading><flux:text>El monto se registra en pesos chilenos enteros, sin decimales.</flux:text></div>
            <flux:input wire:model="montoDemandado" type="number" min="0" step="1" label="Monto demandado" prefix="$" />
            <flux:switch wire:model="tieneCotizaciones" label="Tiene cotizaciones" />
        </flux:card>
    </div>

    <flux:card class="grid gap-5">
        <div><flux:heading size="lg">Observaciones</flux:heading><flux:text>Información importante para la gestión del expediente.</flux:text></div>
        <flux:textarea wire:model="observacionImportante" label="Observación importante" rows="5" maxlength="10000" />
    </flux:card>

    <div class="flex justify-end gap-3">
        <flux:button variant="ghost" :href="$causaId === null ? route('causas.index') : route('causas.show', $causaId)" wire:navigate>Cancelar</flux:button>
        <flux:button variant="primary" type="submit" icon="check" wire:loading.attr="disabled">Guardar causa</flux:button>
    </div>
</form>
