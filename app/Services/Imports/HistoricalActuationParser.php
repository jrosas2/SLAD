<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;
use InvalidArgumentException;

class HistoricalActuationParser
{
    public function __construct(private HistoricalDateParser $dateParser) {}

    /** @return array{items: list<array{fecha: string|null, descripcion: string}>, advertencias: list<string>} */
    public function parse(?string $value): array
    {
        if (blank($value)) {
            return ['items' => [], 'advertencias' => []];
        }

        $original = trim((string) $value);
        $matchCount = preg_match_all(
            '/(?<!\d)(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})\s*:?[\t ]*/',
            $original,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matchCount === false || $matchCount === 0) {
            return [
                'items' => [['fecha' => null, 'descripcion' => $original]],
                'advertencias' => ['La observación se conservó como una actuación única sin fecha.'],
            ];
        }

        $items = [];

        foreach ($matches[0] as $index => $fullMatch) {
            if ($index === 0 && trim(substr($original, 0, $fullMatch[1])) !== '') {
                return $this->fallback($original);
            }

            $descriptionStart = $fullMatch[1] + strlen($fullMatch[0]);
            $descriptionEnd = $matches[0][$index + 1][1] ?? strlen($original);
            $description = trim(substr($original, $descriptionStart, $descriptionEnd - $descriptionStart));

            try {
                $date = $this->dateParser->parse($matches[1][$index][0]);
            } catch (InvalidArgumentException) {
                return $this->fallback($original);
            }

            if ($description === '') {
                return $this->fallback($original);
            }

            $items[] = [
                'fecha' => $date?->toDateString(),
                'descripcion' => Str::squish($description),
            ];
        }

        return ['items' => $items, 'advertencias' => []];
    }

    /** @return array{items: list<array{fecha: string|null, descripcion: string}>, advertencias: list<string>} */
    private function fallback(string $original): array
    {
        return [
            'items' => [['fecha' => null, 'descripcion' => $original]],
            'advertencias' => ['No fue posible separar todas las actuaciones con seguridad; se conservó el texto completo.'],
        ];
    }
}
