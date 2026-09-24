<?php

use App\Support\ChileanRut;

test('normaliza representaciones equivalentes de un RUT válido', function (string $input) {
    expect(ChileanRut::normalize($input))->toBe('12345678-5')
        ->and(ChileanRut::isValid($input))->toBeTrue();
})->with([
    'con puntos' => '12.345.678-5',
    'sin puntos' => '12345678-5',
    'sin guion' => '123456785',
    'con espacios' => ' 12.345.678 - 5 ',
]);

test('normaliza el dígito verificador k a mayúscula', function () {
    expect(ChileanRut::normalize('10.000.013-k'))->toBe('10000013-K')
        ->and(ChileanRut::isValid('10.000.013-k'))->toBeTrue();
});

test('calcula dígitos verificadores numéricos y K', function () {
    expect(ChileanRut::fromBody('12345678'))->toBe('12345678-5')
        ->and(ChileanRut::fromBody('10000013'))->toBe('10000013-K');
});

test('formatea visualmente sin alterar el valor persistente', function () {
    expect(ChileanRut::format('12345678-5'))->toBe('12.345.678-5');
});

test('rechaza RUT estructuralmente inválidos', function (string $input) {
    expect(ChileanRut::normalize($input))->toBeNull()
        ->and(ChileanRut::isValid($input))->toBeFalse();
})->with([
    'caracteres' => '12A34567-5',
    'cuerpo corto' => '123456-0',
    'cuerpo largo' => '123456789-0',
    'sin DV' => '1234567',
    'dos guiones' => '12345678--5',
]);

test('rechaza un dígito verificador incorrecto', function () {
    expect(ChileanRut::normalize('12.345.678-9'))->toBe('12345678-9')
        ->and(ChileanRut::isValid('12.345.678-9'))->toBeFalse();
});
