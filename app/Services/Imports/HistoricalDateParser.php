<?php

namespace App\Services\Imports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class HistoricalDateParser
{
    public function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || (is_string($value) && blank($value))) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            try {
                return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('La fecha serializada de Excel no es válida.');
            }
        }

        $text = trim((string) $value);

        foreach (['!d.m.Y', '!d/m/Y', '!d-m-Y', '!d.m.y', '!d/m/y', '!d-m-y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $text);
                $errors = CarbonImmutable::getLastErrors();
            } catch (\Throwable) {
                continue;
            }

            if ($date !== null && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                if (preg_match('/\d{2}$/', $text) === 1 && $date->year < 100) {
                    $date = $date->addYears(2000);
                }

                return $date->startOfDay();
            }
        }

        throw new InvalidArgumentException("No fue posible interpretar la fecha: {$text}");
    }
}
