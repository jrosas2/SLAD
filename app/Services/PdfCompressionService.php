<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PdfCompressionService
{
    /** @return array{path: string, original_size: int, stored_size: int, comprimido: bool} */
    public function optimize(string $sourcePath): array
    {
        $originalSize = filesize($sourcePath);

        if ($originalSize === false || ! $this->isValidPdf($sourcePath)) {
            throw new \RuntimeException('El archivo no contiene un PDF válido.');
        }

        $binary = config('slad.documents.ghostscript_binary');
        $minimumSize = (int) config('slad.documents.compression_min_size_bytes', 524288);

        if (! is_string($binary) || $binary === '' || $originalSize < $minimumSize) {
            return $this->originalResult($sourcePath, $originalSize);
        }

        $temporaryOutput = tempnam(sys_get_temp_dir(), 'slad-pdf-');

        if ($temporaryOutput === false) {
            return $this->originalResult($sourcePath, $originalSize);
        }

        try {
            $process = new Process([
                $binary,
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS=/printer',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                '-sOutputFile='.$temporaryOutput,
                $sourcePath,
            ]);
            $process->setTimeout(60);
            $process->run();

            $compressedSize = filesize($temporaryOutput);

            if ($process->isSuccessful() && $compressedSize !== false && $compressedSize < $originalSize && $this->isValidPdf($temporaryOutput)) {
                return [
                    'path' => $temporaryOutput,
                    'original_size' => $originalSize,
                    'stored_size' => $compressedSize,
                    'comprimido' => true,
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('No fue posible comprimir un PDF adjunto.', ['exception' => $exception->getMessage()]);
        }

        if (is_file($temporaryOutput)) {
            unlink($temporaryOutput);
        }

        return $this->originalResult($sourcePath, $originalSize);
    }

    public function isValidPdf(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }

    /** @return array{path: string, original_size: int, stored_size: int, comprimido: bool} */
    private function originalResult(string $path, int $size): array
    {
        return ['path' => $path, 'original_size' => $size, 'stored_size' => $size, 'comprimido' => false];
    }
}
