<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('un usuario con contraseña temporal debe cambiarla antes de acceder al sistema', function () {
    $usuario = User::factory()->withTemporaryPassword()->create([
        'password' => 'Temporal-2026!',
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertRedirect(route('password.first-change'));

    $this->get(route('password.first-change'))
        ->assertOk()
        ->assertSee('Crea tu contraseña personal');
});

test('el usuario reemplaza la contraseña temporal y puede continuar', function () {
    $usuario = User::factory()->withTemporaryPassword()->create([
        'password' => 'Temporal-2026!',
    ]);

    $this->actingAs($usuario);

    Livewire::test('pages::auth.first-change-password')
        ->set('current_password', 'Temporal-2026!')
        ->set('password', 'Personal-2026!')
        ->set('password_confirmation', 'Personal-2026!')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    expect($usuario->refresh()->debe_cambiar_password)->toBeFalse()
        ->and(Hash::check('Personal-2026!', $usuario->password))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
});

test('la contraseña temporal correcta es obligatoria para cambiarla', function () {
    $usuario = User::factory()->withTemporaryPassword()->create([
        'password' => 'Temporal-2026!',
    ]);

    $this->actingAs($usuario);

    Livewire::test('pages::auth.first-change-password')
        ->set('current_password', 'incorrecta')
        ->set('password', 'Personal-2026!')
        ->set('password_confirmation', 'Personal-2026!')
        ->call('changePassword')
        ->assertHasErrors(['current_password']);

    expect($usuario->refresh()->debe_cambiar_password)->toBeTrue()
        ->and(Hash::check('Temporal-2026!', $usuario->password))->toBeTrue();
});

test('un usuario sin cambio pendiente no puede abrir la pantalla inicial', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('password.first-change'))
        ->assertRedirect(route('dashboard', absolute: false));
});

test('restablecer la contraseña también resuelve el cambio pendiente', function () {
    $usuario = User::factory()->withTemporaryPassword()->create();

    app(ResetUserPassword::class)->reset($usuario, [
        'password' => 'Restablecida-2026!',
        'password_confirmation' => 'Restablecida-2026!',
    ]);

    expect($usuario->refresh()->debe_cambiar_password)->toBeFalse()
        ->and(Hash::check('Restablecida-2026!', $usuario->password))->toBeTrue();
});
