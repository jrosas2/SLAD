<?php

namespace App\Rules;

use App\Support\ChileanRut;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidChileanRut implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ChileanRut::isValid($value)) {
            $fail('El campo :attribute debe ser un RUT chileno válido.');
        }
    }
}
