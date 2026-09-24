<?php

test('muestra una página 404 personalizada', function () {
    $this->get('/pagina-que-no-existe')
        ->assertNotFound()
        ->assertSee('Página no encontrada')
        ->assertSee('images/errors/zoro-404.png')
        ->assertSee('Volver al inicio');
});
