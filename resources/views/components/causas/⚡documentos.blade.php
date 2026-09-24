<?php

use App\Models\Causa;
use App\Models\DocumentoCausa;
use App\Services\DocumentStorageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $causaId;

    public mixed $archivo = null;

    public string $descripcion = '';

    #[Locked]
    public ?int $documentoAEliminarId = null;

    public bool $mostrarConfirmacionEliminacion = false;

    public function mount(Causa $causa): void
    {
        Gate::authorize('view', $causa);
        Gate::authorize('viewAny', DocumentoCausa::class);
        $this->causaId = $causa->id;
    }

    /** @return Collection<int, DocumentoCausa> */
    #[Computed]
    public function documentos(): Collection
    {
        return DocumentoCausa::query()
            ->where('causa_id', $this->causaId)
            ->with(['causa:id,responsable_id', 'usuario:id,name'])
            ->latest()
            ->get();
    }

    public function guardar(DocumentStorageService $storageService): void
    {
        $causa = Causa::query()->findOrFail($this->causaId);
        Gate::authorize('view', $causa);
        Gate::authorize('create', DocumentoCausa::class);

        $maxKilobytes = max(1, (int) config('slad.documents.max_size_mb', 20)) * 1024;
        $this->validate([
            'archivo' => ['required', 'file', 'extensions:pdf', 'mimetypes:application/pdf', 'max:'.$maxKilobytes],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ], [
            'archivo.max' => 'El documento no puede superar '.((int) config('slad.documents.max_size_mb', 20)).' MB.',
        ]);

        $storageService->store($causa, $this->archivo, auth()->user(), $this->descripcion);

        $this->reset('archivo', 'descripcion');
        unset($this->documentos);
        Flux::toast('Documento adjuntado correctamente.');
    }

    public function confirmarEliminacion(int $documentoId): void
    {
        $documento = $this->documentoDeLaCausa($documentoId);
        Gate::authorize('delete', $documento);

        $this->documentoAEliminarId = $documento->id;
        $this->mostrarConfirmacionEliminacion = true;
    }

    public function eliminar(DocumentStorageService $storageService): void
    {
        if ($this->documentoAEliminarId === null) {
            return;
        }

        $documento = $this->documentoDeLaCausa($this->documentoAEliminarId);
        Gate::authorize('delete', $documento);
        $storageService->delete($documento);

        $this->reset('documentoAEliminarId', 'mostrarConfirmacionEliminacion');
        unset($this->documentos);
        Flux::toast('Documento eliminado correctamente.');
    }

    public function cancelarEliminacion(): void
    {
        $this->reset('documentoAEliminarId', 'mostrarConfirmacionEliminacion');
    }

    public function formatoTamano(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 2, ',', '.').' MB';
    }

    private function documentoDeLaCausa(int $documentoId): DocumentoCausa
    {
        return DocumentoCausa::query()
            ->with('causa:id,responsable_id')
            ->where('causa_id', $this->causaId)
            ->findOrFail($documentoId);
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
        <div>
            <flux:heading size="lg">Documentos adjuntos</flux:heading>
            <flux:text class="text-sm">Archivos PDF privados asociados a esta causa.</flux:text>
        </div>
        <flux:badge color="zinc">{{ $this->documentos->count() }} {{ Str::plural('documento', $this->documentos->count()) }}</flux:badge>
    </div>

    @can('create', DocumentoCausa::class)
        <form wire:submit="guardar" class="grid gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                <flux:input wire:model="archivo" type="file" label="PDF" accept="application/pdf,.pdf" />
                <flux:textarea wire:model="descripcion" label="Descripción (opcional)" rows="1" />
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="archivo,guardar">Adjuntar PDF</flux:button>
            </div>
            <div wire:loading wire:target="archivo" class="text-sm text-zinc-500">Cargando archivo…</div>
            <flux:error name="archivo" />
            <flux:error name="descripcion" />
        </form>
    @endcan

    <div class="grid gap-3">
        @forelse ($this->documentos as $documento)
            <article class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <flux:icon.document-text class="size-5 shrink-0 text-red-600" />
                        <p class="truncate font-medium">{{ $documento->nombre_original }}</p>
                        @if ($documento->comprimido)
                            <flux:badge color="green" size="sm">Comprimido</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $this->formatoTamano($documento->tamano_almacenado) }}
                        @if ($documento->comprimido && $documento->tamano_original > 0)
                            · reducción {{ number_format((1 - ($documento->tamano_almacenado / $documento->tamano_original)) * 100, 0) }}%
                        @endif
                        · {{ $documento->created_at?->format('d-m-Y H:i') }}
                        @if ($documento->usuario) · {{ $documento->usuario->name }} @endif
                    </p>
                    @if ($documento->descripcion)<p class="mt-1 text-sm">{{ $documento->descripcion }}</p>@endif
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <flux:button size="sm" variant="ghost" icon="eye" :href="route('causas.documentos.view', [$this->causaId, $documento])" target="_blank">Ver</flux:button>
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('causas.documentos.download', [$this->causaId, $documento])">Descargar</flux:button>
                    @can('delete', $documento)
                        <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmarEliminacion({{ $documento->id }})">Eliminar</flux:button>
                    @endcan
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">Aún no hay documentos adjuntos.</div>
        @endforelse
    </div>

    <flux:modal wire:model="mostrarConfirmacionEliminacion" class="max-w-md">
        <div class="grid gap-4">
            <flux:heading size="lg">¿Eliminar documento?</flux:heading>
            <flux:text>El archivo se eliminará del almacenamiento privado y no podrá recuperarse.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelarEliminacion">Cancelar</flux:button>
                <flux:button variant="danger" wire:click="eliminar">Eliminar documento</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
