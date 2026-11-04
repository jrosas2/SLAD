<?php

use App\Services\Pjud\EstadoDiarioSupremaParser;

test('lee el Estado Diario de la Corte Suprema en formato JSON', function () {
    $json = json_encode([
        ['COD_TRIBUNAL' => 1, 'COD_LIBRO' => 2, 'CRR_INGRESO_RECURSO' => 407, 'FEC_ERA_RECURSO' => 2026, 'FLG_RESERVADO' => 0, 'CARATULADO' => 'PÉREZ CON FISCO', 'CANT_RESOLUCIONES' => 1],
        ['COD_TRIBUNAL' => 1, 'COD_LIBRO' => 4, 'CRR_INGRESO_RECURSO' => 1188, 'FEC_ERA_RECURSO' => 2025, 'FLG_RESERVADO' => 1, 'CARATULADO' => null, 'CANT_RESOLUCIONES' => 2],
        ['COD_TRIBUNAL' => 1, 'CRR_INGRESO_RECURSO' => null, 'FEC_ERA_RECURSO' => 2025],
    ]);

    $entradas = (new EstadoDiarioSupremaParser)->parse((string) $json);

    expect($entradas)->toHaveCount(2)
        ->and($entradas[0]->numeroIngreso)->toBe('407-2026')
        ->and($entradas[0]->caratulado)->toBe('PÉREZ CON FISCO')
        ->and($entradas[0]->tramite)->toBe('1')
        ->and($entradas[0]->datos)->toBe(['libro' => '2', 'reservado' => '0'])
        ->and($entradas[1]->numeroIngreso)->toBe('1188-2025')
        ->and($entradas[1]->caratulado)->toBeNull();
});

test('una respuesta vacía o que no es JSON no genera filas', function () {
    $parser = new EstadoDiarioSupremaParser;

    expect($parser->parse(''))->toBe([])
        ->and($parser->parse('[]'))->toBe([])
        ->and($parser->parse('<html>error</html>'))->toBe([]);
});
