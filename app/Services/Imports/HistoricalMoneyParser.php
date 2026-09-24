<?php

namespace App\Services\Imports;

use Illuminate\Support\Str;
use InvalidArgumentException;

class HistoricalMoneyParser
{
    public function parse(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && blank($value))) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
                throw new InvalidArgumentException('El monto debe ser un valor CLP entero y no negativo.');
            }

            return (int) $value;
        }

        $text = (string) Str::of((string) $value)
            ->upper()
            ->replace(['$', 'CLP', ' '], '');

        if (! preg_match('/^\d{1,3}(?:\.\d{3})*(?:,00)?$|^\d+(?:[.,]00)?$/', $text)) {
            throw new InvalidArgumentException("No fue posible interpretar el monto CLP: {$value}");
        }

        $integer = preg_replace('/[.,]00$/', '', $text);
        $integer = str_replace('.', '', (string) $integer);

        if (! ctype_digit($integer)) {
            throw new InvalidArgumentException("No fue posible interpretar el monto CLP: {$value}");
        }

        return (int) $integer;
    }
}
