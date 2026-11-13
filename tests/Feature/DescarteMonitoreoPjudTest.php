<?php

use App\Enums\AccionAuditoria;
use App\Enums\Permiso;
use App\Models\Auditoria;
use App\Models\Causa;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function movimientoParaDescartar(Causa $causa, array $atributos = []): PjudMovimiento
{
    return PjudMovimiento::query()->create([
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => 4901014,
        'fecha_estado_diario' => '2026-11-10',
        'numero_ingreso' => $causa->numero_causa ?? '1-2026',
        'tipo_coincidencia' => 'parcial',
        'tribunal_nombre' => 'CORTE DE APELACIONES DE TEMUCO',
        'tramite' => '1',
        'huella' => hash('sha256', 'movimiento-'.$causa->id.'-'.uniqid('', true)),
        ...$atributos,
    ]);
}

function programacionParaDescartar(Causa $causa, array $atributos = []): PjudProgramacion
{
    return PjudProgramacion::query()->create([
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => 4901014,
        'tribunal_nombre' => 'CORTE DE APELACIONES DE TEMUCO',
        'tipo_tabla_codigo' => '3',
        'tipo_tabla' => 'ordinaria',
        'fecha_vista' => '2026-11-19',
        'sala_numero' => '2',
        'sala' => 'SEGUNDA',
        'numero_orden' => 4,
        'numero_ingreso' => $causa->numero_causa ?? '1-2026',
        'tipo_coincidencia' => 'parcial',
        'huella' => hash('sha256', 'programacion-'.$causa->id.'-'.uniqid('', true)),
        'detectada_en' => now(),
        ...$atributos,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00'));
});

test('el administrador elimina un movimiento con motivo obligatorio y queda en la bitácora', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026']);
    $movimiento = movimientoParaDescartar($causa, ['tramite' => 'Falso positivo']);

    $this->actingAs($administrador);
    Livewire::test('pages::monitoreo.causas')
        ->assertSee('Falso positivo')
        ->call('confirmarDescarte', 'movimiento', $movimiento->id)
        ->assertSet('showDescarte', true)
        ->assertSee('¿Eliminar este registro del monitoreo?')
        ->call('descartar')
        ->assertHasErrors(['motivoDescarte' => 'required'])
        ->set('motivoDescarte', 'No corresponde a esta causa, es otra con el mismo número.')
        ->call('descartar')
        ->assertHasNoErrors()
        ->assertSet('showDescarte', false)
        ->assertDontSee('Falso positivo');

    $descartado = PjudMovimiento::withTrashed()->findOrFail($movimiento->id);
    expect($descartado->trashed())->toBeTrue()
        ->and($descartado->descartado_por)->toBe($administrador->id)
        ->and($descartado->motivo_descarte)->toBe('No corresponde a esta causa, es otra con el mismo número.');

    $auditoria = Auditoria::query()->where('modelo', PjudMovimiento::class)->where('modelo_id', $movimiento->id)->firstOrFail();
    expect($auditoria->accion)->toBe(AccionAuditoria::MonitoreoDescartado)
        ->and($auditoria->user_id)->toBe($administrador->id)
        ->and($auditoria->causa_id)->toBe($causa->id)
        ->and($auditoria->valores_anteriores['motivo_descarte'])->toBe('No corresponde a esta causa, es otra con el mismo número.')
        ->and($auditoria->valores_anteriores['tramite'])->toBe('Falso positivo');
});

test('el registro descartado no se vuelve a crear ni a notificar en el siguiente escaneo', function () {
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026']);
    $movimiento = movimientoParaDescartar($causa, ['huella' => str_repeat('a', 64)]);
    $movimiento->delete();

    $repetido = PjudMovimiento::withTrashed()->createOrFirst(['huella' => str_repeat('a', 64)], [
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => 4901014,
        'fecha_estado_diario' => '2026-11-10',
        'numero_ingreso' => '1436-2026',
        'tribunal_nombre' => 'CORTE DE APELACIONES DE TEMUCO',
    ]);

    expect($repetido->wasRecentlyCreated)->toBeFalse()
        ->and(PjudMovimiento::query()->count())->toBe(0)
        ->and(PjudMovimiento::withTrashed()->count())->toBe(1);
});

test('al eliminar una vista en sala se borra su evento de Google Calendar', function () {
    config()->set('services.google_calendar', [
        'enabled' => true,
        'calendar_id' => 'slad@empresa.cl',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'event_duration_minutes' => 30,
    ]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response(null, 204),
    ]);

    $this->actingAs(User::factory()->administrador()->create());
    $programacion = programacionParaDescartar(Causa::factory()->create(['numero_causa' => '1436-2026']));
    $programacion->forceFill(['google_calendar_event_id' => 'evento-sala-1', 'notificado_at' => now()])->save();

    Livewire::test('pages::monitoreo.salas')
        ->call('confirmarDescarte', 'programacion', $programacion->id)
        ->set('motivoDescarte', 'La causa no está en esta tabla.')
        ->call('descartar')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/events/evento-sala-1'));
    expect(PjudProgramacion::withTrashed()->findOrFail($programacion->id))
        ->trashed()->toBeTrue()
        ->google_calendar_event_id->toBeNull();
});

test('desde el detalle de la causa se elimina el registro y deja de mostrarse', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026']);
    $vista = programacionParaDescartar($causa);

    $this->actingAs($administrador);
    Livewire::test('pages::causas.show', ['causa' => $causa])
        ->assertSee('SEGUNDA')
        ->call('confirmarDescarte', 'programacion', $vista->id)
        ->set('motivoDescarte', 'Falso positivo del monitoreo.')
        ->call('descartar')
        ->assertHasNoErrors()
        ->assertSee('Sin vistas programadas detectadas.');

    expect(PjudProgramacion::query()->whereKey($vista->id)->exists())->toBeFalse();
});

test('sin el permiso monitoreo.eliminar no se puede eliminar', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026', 'responsable_id' => $abogado->id]);
    $movimiento = movimientoParaDescartar($causa);

    expect($abogado->can(Permiso::MonitoreoEliminar->value))->toBeFalse();

    $this->actingAs($abogado);
    Livewire::test('pages::monitoreo.causas')
        ->assertDontSee('confirmarDescarte')
        ->call('confirmarDescarte', 'movimiento', $movimiento->id)
        ->assertForbidden();

    expect(PjudMovimiento::query()->whereKey($movimiento->id)->exists())->toBeTrue();
});

test('el permiso monitoreo.eliminar se puede asignar a otro rol', function () {
    $abogado = User::factory()->abogado()->create();
    $abogado->givePermissionTo(Permission::findOrCreate(Permiso::MonitoreoEliminar->value, 'web'));
    $causa = Causa::factory()->create(['numero_causa' => '1436-2026', 'responsable_id' => $abogado->id]);
    $ajena = movimientoParaDescartar(Causa::factory()->create(['responsable_id' => User::factory()->create()->id]));
    $propio = movimientoParaDescartar($causa);

    $this->actingAs($abogado->fresh());
    Livewire::test('pages::monitoreo.causas')
        ->call('confirmarDescarte', 'movimiento', $ajena->id)
        ->assertNotFound();

    Livewire::test('pages::monitoreo.causas')
        ->call('confirmarDescarte', 'movimiento', $propio->id)
        ->set('motivoDescarte', 'Coincidencia parcial incorrecta.')
        ->call('descartar')
        ->assertHasNoErrors();

    expect(PjudMovimiento::query()->whereKey($propio->id)->exists())->toBeFalse();
});
