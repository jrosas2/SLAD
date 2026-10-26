<?php

use App\Services\Pjud\EstadoDiarioHtmlParser;

function parserEstadoDiario(): EstadoDiarioHtmlParser
{
    return new EstadoDiarioHtmlParser([
        'numero_ingreso' => ['rol', 'rit', 'n ingreso', 'nro ingreso', 'numero ingreso', 'ingreso', 'causa'],
        'tribunal' => ['tribunal', 'juzgado'],
        'caratulado' => ['partes', 'caratulado', 'caratula'],
        'tramite' => ['providencias', 'tramite', 'descripcion', 'resolucion', 'actuacion'],
        'fecha' => ['fecha'],
    ]);
}

test('lee el estado diario por nombre de columna, sin importar el orden', function () {
    $html = <<<'HTML'
        <html><body>
        <table><tr><td>Menú</td></tr></table>
        <table>
            <thead><tr><th>Caratulado</th><th>Rol</th><th>Tribunal</th><th>Trámite</th><th>Fecha</th></tr></thead>
            <tbody>
                <tr><td>PÉREZ / BANCO</td><td>C-1234-2025</td><td>1º Juzgado Civil de Santiago</td><td>Resolución</td><td>16/10/2026</td></tr>
                <tr><td>SOTO / ISAPRE</td><td>C-99-2026</td><td>1º Juzgado Civil de Santiago</td><td>Notificación</td><td>16/10/2026</td></tr>
                <tr><td colspan="5">Total: 2</td></tr>
            </tbody>
        </table>
        </body></html>
        HTML;

    $entradas = parserEstadoDiario()->parse($html);

    expect($entradas)->toHaveCount(2)
        ->and($entradas[0]->numeroIngreso)->toBe('C-1234-2025')
        ->and($entradas[0]->tribunal)->toBe('1º Juzgado Civil de Santiago')
        ->and($entradas[0]->caratulado)->toBe('PÉREZ / BANCO')
        ->and($entradas[0]->tramite)->toBe('Resolución')
        ->and($entradas[1]->numeroIngreso)->toBe('C-99-2026');
});

test('devuelve vacío si la página no trae tabla de estado diario', function () {
    expect(parserEstadoDiario()->parse('<html><body><p>Sin resultados</p></body></html>'))->toBe([])
        ->and(parserEstadoDiario()->parse(''))->toBe([]);
});

test('lee el formato real del estado diario de pjud.cl', function () {
    $html = <<<'HTML'
        <style>th { font-size: 13px; }</style>
        <div class="panel panel-primary border"><div class="panel-heading">Estados diario competencia civil del día 15-10-2026</div>
        <div class="panel-body"><div class="table-responsive">
        <table id="data-table-estado-diario" class="table table-hover table-sm text-left">
            <thead><tr><th>N°</th><th width="200px">Número de Ingreso</th><th>Partes</th><th>Providencias</th></tr></thead>
            <tbody>
                <tr class="odd gradeX"><td> 1 </td><td> <a href="#" data-tipocausa="A" data-rol="10" data-era="2026" data-codtribunal="6053101" data-tipojuzgado="Z" data-date="15-10-2026" data-codoracle="259">A-10-2026</a> </td><td>PARTES</td><td> 1 </td></tr>
                <tr class="odd gradeX"><td> 2 </td><td> <a href="#" data-tipocausa="C" data-rol="2" data-era="1951">C-2-1951</a> </td><td>OTRAS PARTES</td><td> 3 </td></tr>
            </tbody>
        </table></div></div></div>
        HTML;

    $entradas = parserEstadoDiario()->parse($html);

    expect($entradas)->toHaveCount(2)
        ->and($entradas[0]->numeroIngreso)->toBe('A-10-2026')
        ->and($entradas[0]->caratulado)->toBe('PARTES')
        ->and($entradas[0]->tramite)->toBe('1')
        ->and($entradas[0]->datos)->toMatchArray(['rol' => '10', 'era' => '2026', 'tipocausa' => 'A', 'codoracle' => '259'])
        ->and($entradas[1]->numeroIngreso)->toBe('C-2-1951');
});
