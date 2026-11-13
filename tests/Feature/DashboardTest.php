<?php

use App\Models\Materia;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('inactive authenticated users are logged out', function () {
    $user = User::factory()->create(['activo' => false]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('la impresión del panel principal lleva la cabecera con el logo de SLAD', function () {
    $this->actingAs(User::factory()->administrador()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('images/logo-slad-impresion.png', false)
        ->assertSee('Panel administrativo y jurídico — Estadísticas')
        ->assertSee('.dashboard-print-header { display: none; }', false);
});

test('la impresión del panel principal muestra los filtros aplicados', function () {
    $materia = Materia::factory()->create(['nombre' => 'CIVIL']);

    $this->actingAs(User::factory()->administrador()->create())
        ->get(route('dashboard', ['anio' => 2026, 'materiaFilter' => $materia->id]))
        ->assertOk()
        ->assertSee('Año: 2026 · Materia: CIVIL');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Sin filtros (todos los registros visibles)');
});
