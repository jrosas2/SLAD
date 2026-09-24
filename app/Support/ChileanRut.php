<?php

namespace App\Support;

use InvalidArgumentException;

final class ChileanRut
{
    private const MIN_BODY_LENGTH = 7;

    private const MAX_BODY_LENGTH = 8;

    private function __construct() {}

    public static function normalize(string $rut): ?string
    {
        $clean = mb_strtoupper(trim($rut), 'UTF-8');
        $clean = str_replace(['.', ' ', "\t", "\r", "\n", "\u{00A0}"], '', $clean);
        $clean = str_replace(['–', '—', '−'], '-', $clean);

        $minimumLength = self::MIN_BODY_LENGTH + 1;
        $maximumLength = self::MAX_BODY_LENGTH + 2;

        if (mb_strlen($clean) < $minimumLength || mb_strlen($clean) > $maximumLength) {
            return null;
        }

        if (! preg_match('/^(\d{7,8})-?([\dK])$/', $clean, $matches)) {
            return null;
        }

        return $matches[1].'-'.$matches[2];
    }

    public static function normalizeOrFail(string $rut): string
    {
        return self::normalize($rut)
            ?? throw new InvalidArgumentException('El RUT no posee un formato válido.');
    }

    public static function isValid(string $rut): bool
    {
        $normalized = self::normalize($rut);

        if ($normalized === null) {
            return false;
        }

        [$body, $verificationDigit] = explode('-', $normalized);

        return hash_equals(self::calculateVerificationDigit($body), $verificationDigit);
    }

    public static function fromBody(string $body): string
    {
        if (! preg_match('/^\d{7,8}$/', $body)) {
            throw new InvalidArgumentException('El cuerpo del RUT debe contener entre 7 y 8 dígitos.');
        }

        return $body.'-'.self::calculateVerificationDigit($body);
    }

    public static function format(string $rut): string
    {
        $normalized = self::normalizeOrFail($rut);
        [$body, $verificationDigit] = explode('-', $normalized);

        return number_format((int) $body, 0, ',', '.').'-'.$verificationDigit;
    }

    private static function calculateVerificationDigit(string $body): string
    {
        $sum = 0;
        $multiplier = 2;

        for ($position = strlen($body) - 1; $position >= 0; $position--) {
            $sum += ((int) $body[$position]) * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        return match (11 - ($sum % 11)) {
            11 => '0',
            10 => 'K',
            default => (string) (11 - ($sum % 11)),
        };
    }
}
