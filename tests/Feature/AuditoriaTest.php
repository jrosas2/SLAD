<?php

use App\Enums\AccionAuditoria;
use App\Enums\Permiso;
use App\Models\Actuacion;
use App\Models\Auditoria;
use App\Models\Causa;
use App\Models\MovimientoFinanciero;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('crear, modificar y eliminar una causa conserva la trazabilidad', function () {
    $usuario = User::factory()->administrador()->create();
    $this->actingAs($usuario);
    $causa = Causa::factory()->create(['nombre' => 'Causa inicial']);

    $creado = Auditoria::query()->where('modelo', Causa::class)->where('modelo_id', $causa->id)->where('accion', AccionAuditoria::Creado)->latest()->firstOrFail();
    expect($creado->usuario->is($usuario))->toBeTrue()
        ->and($creado->valores_anteriores)->toBeNull()
        ->and($creado->valores_nuevos['nombre'])->toBe('Causa inicial');

    $causa->update(['nombre' => 'Causa actualizada']);
    $modificado = Auditoria::query()->where('modelo_id', $causa->id)->where('accion', AccionAuditoria::Modificado)->latest()->firstOrFail();
    expect($modificado->valores_anteriores)->toMatchArray(['nombre' => 'Causa inicial'])
        ->and($modificado->valores_nuevos)->toMatchArray(['nombre' => 'Causa actualizada'])
        ->and($modificado->valores_anteriores)->not->toHaveKeys(['created_at', 'updated_at']);

    $causa->delete();
    $eliminado = Auditoria::query()->where('modelo_id', $causa->id)->where('accion', AccionAuditoria::Eliminado)->latest()->firstOrFail();
    expect($eliminado->valores_anteriores['nombre'])->toBe('Causa actualizada')
        ->and($eliminado->valores_nuevos)->toBeNull();
});

test('actuaciones y movimientos quedan relacionados directamente con su causa', function () {
    $usuario = User::factory()->administrador()->create();
    $this->actingAs($usuario);
    $causa = Causa::factory()->create();
    $actuacion = Actuacion::factory()->for($causa)->create();
    $movimiento = MovimientoFinanciero::factory()->for($causa)->create();

    expect(Auditoria::query()->where('modelo_id', $actuacion->id)->where('causa_id', $causa->id)->exists())->toBeTrue()
        ->and(Auditoria::query()->where('modelo_id', $movimiento->id)->where('causa_id', $causa->id)->exists())->toBeTrue();
});

test('campos sensibles nunca se guardan en la auditoría de usuarios', function () {
    $usuario = User::factory()->administrador()->create();
    $this->actingAs($usuario);
    $nuevo = User::factory()->create(['password' => 'secreto-original']);

    $auditoria = Auditoria::query()->where('modelo', User::class)->where('modelo_id', $nuevo->id)->latest()->firstOrFail();
    expect($auditoria->valores_nuevos)->not->toHaveKey('password')
        ->and(json_encode($auditoria->toArray()))->not->toContain('secreto-original')
        ->and(Hash::check('secreto-original', $nuevo->password))->toBeTrue();

    Livewire::test('pages::admin.auditoria.index')
        ->call('verDetalle', $auditoria->id)
        ->assertSee($nuevo->name);
});

test('la bitácora requiere permiso y permite combinar filtros', function () {
    $administrador = User::factory()->administrador()->create();
    $otro = User::factory()->consulta()->create();
    $causa = Causa::factory()->create(['numero_causa' => 'AUD-100', 'nombre' => 'Causa auditada']);
    $this->actingAs($administrador);

    expect(Auditoria::query()->where('modelo_id', $causa->id)->exists())->toBeTrue();

    Livewire::test('pages::admin.auditoria.index')
        ->set('causaSearch', 'AUD-100')
        ->set('accion', AccionAuditoria::Creado->value)
        ->assertSee('AUD-100');

    $this->actingAs($otro)->get(route('admin.auditoria.index'))->assertForbidden();
    expect($administrador->can(Permiso::AuditoriaVer->value))->toBeTrue();
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
