<?php

namespace App\Http\Controllers;

use App\Models\Causa;
use App\Models\DocumentoCausa;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CausaDocumentoController extends Controller
{
    public function view(Causa $causa, DocumentoCausa $documento): BinaryFileResponse
    {
        Gate::authorize('view', $documento);
        $this->ensureDocumentBelongsToCausa($causa, $documento);

        return response()->file($this->documentPath($documento), [
            'Content-Type' => $documento->mime_type,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($documento->nombre_original).'"',
        ]);
    }

    public function download(Causa $causa, DocumentoCausa $documento): BinaryFileResponse
    {
        Gate::authorize('view', $documento);
        $this->ensureDocumentBelongsToCausa($causa, $documento);

        return response()->download($this->documentPath($documento), $documento->nombre_original, [
            'Content-Type' => $documento->mime_type,
        ]);
    }

    private function ensureDocumentBelongsToCausa(Causa $causa, DocumentoCausa $documento): void
    {
        abort_unless($documento->causa_id === $causa->id, Response::HTTP_NOT_FOUND);
    }

    private function documentPath(DocumentoCausa $documento): string
    {
        abort_unless(Storage::disk('local')->exists($documento->ruta), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->path($documento->ruta);
    }

    private function safeFilename(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }
}
