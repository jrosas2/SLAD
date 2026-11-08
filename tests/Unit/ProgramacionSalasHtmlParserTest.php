<?php

use App\Services\Pjud\ProgramacionSalasHtmlParser;

// Respuestas reales del PJUD (recortadas), noviembre de 2026.
const HTML_SALAS_PJUD = <<<'HTML'
<table id="dataTypeTable" class="table table-hover table-sm text-left">
<thead><tr><th class="text-nowrap text-left">Fecha</th><th class="text-nowrap text-left">Sala</th><th class="text-nowrap text-left">Relator</th></tr></thead>
<tbody>
<tr class="odd gradeX"><td class="text-left"> 09/11/2026 </td><td class="text-left"><a data-toggle="modal" href="#modalConstitucionSala" onclick="linkConstitucionSala('1','4901014','3','09/11/2026','');"> PRIMERA </a></td><td class="text-left"> IGNACIO PICHUN PINO </td></tr>
<tr class="odd gradeX"><td class="text-left"> 10/11/2026 </td><td class="text-left"><a data-toggle="modal" href="#modalConstitucionSala" onclick="linkConstitucionSala('2','4901014','3','10/11/2026','');"> SEGUNDA </a></td><td class="text-left"> GLORIA FERNANDEZ ORTIZ </td></tr>
</tbody></table>
HTML;

const HTML_CONSTITUCION_PJUD = <<<'HTML'
<b>Constitución de Sala - Instalación provisional y sujeta a revisión en el día de la vista de la causa (art. 90 COT)</b>
<table id="dataIntegrations" class="table table-hover table-sm text-left">
<thead><tr><th class="text-nowrap">Cargo</th><th class="text-nowrap">Nombre</th></tr></thead>
<tbody><tr class="odd gradeX"><td> Relator </td><td> ELIZABETH VERONICA ARAYA JULIO </td></tr></tbody>
</table>
<table id="dataIntegationRoom" class="table table-hover table-sm text-left">
<thead><tr><th class="text-nowrap">N°</th><th>Causa</th><th class="text-nowrap">Ingreso</th></tr></thead>
<tbody>
<tr class="odd gradeX"><td> 1 </td><td class="text-left"> INVERSIONES KRAAF SPA/COMISIÓN PARA EL MERCADO FINANCIERO (CMF) </td><td> 55754 - 2026 </td></tr>
<tr class="odd gradeX"><td> 2 </td><td class="text-left"> YAÑEZ VERGARA HERIBERTO CON FISCO DE CHILE- CDE </td><td> 58977 - 2024 </td></tr>
</tbody></table>
HTML;

test('lee las salas y días con tabla desde el enlace linkConstitucionSala', function () {
    $salas = (new ProgramacionSalasHtmlParser)->salas(HTML_SALAS_PJUD);

    expect($salas)->toHaveCount(2)
        ->and($salas[0]['fecha']->toDateString())->toBe('2026-11-09')
        ->and($salas[0]['sala_numero'])->toBe('1')
        ->and($salas[0]['sala'])->toBe('PRIMERA')
        ->and($salas[0]['relator'])->toBe('IGNACIO PICHUN PINO')
        ->and($salas[1]['fecha']->toDateString())->toBe('2026-11-10')
        ->and($salas[1]['sala_numero'])->toBe('2');
});

test('lee las causas en tabla de una sala con su número de ingreso y relator', function () {
    $constitucion = (new ProgramacionSalasHtmlParser)->causas(HTML_CONSTITUCION_PJUD);

    expect($constitucion['relator'])->toBe('ELIZABETH VERONICA ARAYA JULIO')
        ->and($constitucion['causas'])->toHaveCount(2)
        ->and($constitucion['causas'][0])->toBe([
            'orden' => 1,
            'caratulado' => 'INVERSIONES KRAAF SPA/COMISIÓN PARA EL MERCADO FINANCIERO (CMF)',
            'numero_ingreso' => '55754 - 2026',
        ])
        ->and($constitucion['causas'][1]['numero_ingreso'])->toBe('58977 - 2024');
});

test('una respuesta vacía o sin tabla no genera filas', function () {
    $parser = new ProgramacionSalasHtmlParser;

    expect($parser->salas(''))->toBe([])
        ->and($parser->salas('<p>No hay datos disponibles</p>'))->toBe([])
        ->and($parser->causas('Registro(s) no encontrado(s).'))->toBe(['relator' => null, 'causas' => []]);
});
