<?php

namespace App\Services;

use App\Enums\AccionAuditoria;
use App\Models\Causa;
use App\Models\DocumentoCausa;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentStorageService
{
    public function __construct(private PdfCompressionService $compressionService, private AuditoriaService $auditoriaService) {}

    public function store(Causa $causa, UploadedFile $file, User $user, ?string $description = null): DocumentoCausa
    {
        $sourcePath = $file->getRealPath();

        if ($sourcePath === false || ! $this->compressionService->isValidPdf($sourcePath)) {
            throw ValidationException::withMessages(['archivo' => 'El archivo debe ser un PDF válido.']);
        }

        $optimized = $this->compressionService->optimize($sourcePath);
        $hash = hash_file('sha256', $optimized['path']);

        if ($hash === false) {
            throw new \RuntimeException('No fue posible verificar el archivo adjunto.');
        }

        if (DocumentoCausa::query()->where('causa_id', $causa->id)->where('hash_sha256', $hash)->exists()) {
            $this->removeTemporaryFile($optimized['path'], $sourcePath);
            throw ValidationException::withMessages(['archivo' => 'Este documento ya está adjuntado a la causa.']);
        }

        $storedPath = null;

        try {
            $storedPath = Storage::disk('local')->putFileAs(
                'causas/'.$causa->id,
                new File($optimized['path']),
                (string) Str::uuid().'.pdf',
            );

            if ($storedPath === false) {
                throw new \RuntimeException('No fue posible guardar el archivo adjunto.');
            }

            $document = DB::transaction(function () use ($causa, $user, $file, $description, $optimized, $hash, $storedPath): DocumentoCausa {
                $document = DocumentoCausa::query()->create([
                    'causa_id' => $causa->id,
                    'user_id' => $user->id,
                    'nombre_original' => $file->getClientOriginalName(),
                    'nombre_archivo' => basename($storedPath),
                    'ruta' => $storedPath,
                    'mime_type' => 'application/pdf',
                    'tamano_original' => $optimized['original_size'],
                    'tamano_almacenado' => $optimized['stored_size'],
                    'comprimido' => $optimized['comprimido'],
                    'hash_sha256' => $hash,
                    'descripcion' => filled($description) ? trim($description) : null,
                ]);

                $this->auditoriaService->record($document, AccionAuditoria::DocumentoAdjuntado, null, [
                    'causa_id' => $causa->id,
                    'nombre_original' => $document->nombre_original,
                    'tamano_almacenado' => $document->tamano_almacenado,
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        } finally {
            $this->removeTemporaryFile($optimized['path'], $sourcePath);
        }

        return $document;
    }

    public function delete(DocumentoCausa $document): void
    {
        if (Storage::disk('local')->exists($document->ruta) && ! Storage::disk('local')->delete($document->ruta)) {
            throw new \RuntimeException('No fue posible eliminar el archivo adjunto.');
        }

        DB::transaction(function () use ($document): void {
            $this->auditoriaService->record($document, AccionAuditoria::DocumentoEliminado, [
                'causa_id' => $document->causa_id,
                'nombre_original' => $document->nombre_original,
                'tamano_almacenado' => $document->tamano_almacenado,
            ], null);
            $document->delete();
        });
    }

    private function removeTemporaryFile(string $path, string $sourcePath): void
    {
        if ($path !== $sourcePath && is_file($path)) {
            unlink($path);
        }
    }
}
