<?php

use App\Models\User;

test('la portada pública presenta la identidad de SLAD', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Sistema Logístico de Administración de Derecho')
        ->assertSee('Gestión Inteligente de Logística Legal')
        ->assertSee('Infraestructura Funcional')
        ->assertSee('Seguridad y roles')
        ->assertSee('Ingresar')
        ->assertSee('fixed inset-x-0 top-0 z-50', escape: false)
        ->assertSee('fixed inset-x-0 bottom-0 z-50', escape: false)
        ->assertDontSee('Laravel has an incredibly rich ecosystem');
});

test('la portada dirige al panel cuando el usuario ya inició sesión', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Ir al panel')
        ->assertSee(route('dashboard'), escape: false);
});
