<?php

use App\Actions\Causas\ImportarResponsableHistorico;
use App\Models\Causa;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

test('la importación administrativa asigna el responsable mediante users codigo', function () {
    $administrador = User::factory()->administrador()->create([]);
    $responsable = User::factory()->abogado()->create(['codigo' => 'FV']);
    $causa = Causa::factory()->create();

    $warning = app(ImportarResponsableHistorico::class)->handle($administrador, $causa, ' fv ');

    expect($warning)->toBeNull()
        ->and($causa->refresh()->responsable->is($responsable))->toBeTrue();
});

test('un código histórico desconocido advierte deja la causa sin responsable y no crea usuarios', function () {
    $administrador = User::factory()->administrador()->create([]);
    $responsable = User::factory()->abogado()->create(['codigo' => 'FV']);
    $causa = Causa::factory()->create();
    $causa->responsable()->associate($responsable);
    $causa->save();
    $usersBefore = User::query()->count();

    $warning = app(ImportarResponsableHistorico::class)->handle($administrador, $causa, 'XX');

    expect($warning)->toContain('XX')
        ->and($causa->refresh()->responsable_id)->toBeNull()
        ->and(User::query()->count())->toBe($usersBefore);
});

test('la importación histórica es exclusiva del administrador', function () {
    $abogado = User::factory()->abogado()->create([]);
    $causa = Causa::factory()->create();

    expect(fn () => app(ImportarResponsableHistorico::class)->handle($abogado, $causa, null))
        ->toThrow(AuthorizationException::class);
});
