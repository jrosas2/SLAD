<?php

use Illuminate\Support\Facades\Route;

test('el registro público de usuarios está deshabilitado', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
