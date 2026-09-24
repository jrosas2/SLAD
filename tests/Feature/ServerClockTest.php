<?php

use Livewire\Livewire;

test('muestra la hora configurada del servidor', function () {
    $hora = now(config('app.timezone'))->format('H:i');
    $fecha = now(config('app.timezone'))->format('d-m-Y');

    Livewire::test('sidebar.server-clock')
        ->assertSet('horaServidor', $hora)
        ->assertSet('fechaServidor', $fecha)
        ->assertSee('Hora del servidor')
        ->assertSee($hora)
        ->call('actualizar')
        ->assertSet('horaServidor', now(config('app.timezone'))->format('H:i'));
});
