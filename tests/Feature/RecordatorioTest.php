<?php

use App\Enums\EstadoRecordatorio;
use App\Enums\Permiso;
use App\Models\Causa;
use App\Models\Recordatorio;
use App\Models\Role;
use App\Models\User;
use App\Services\RecordatorioService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

test('crea un recordatorio para el responsable de la causa por defecto', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $this->actingAs($abogado);

    $recordatorio = app(RecordatorioService::class)->create($causa, $abogado, [
        'titulo' => 'PRESENTAR ESCRITO', 'descripcion' => 'Antes del cierre.',
        'fecha_hora' => CarbonImmutable::parse('2026-09-10 10:00:00'),
        'recordar_minutos_antes' => 60, 'user_id' => null,
    ]);

    expect($recordatorio->user_id)->toBe($abogado->id)
        ->and($recordatorio->estado)->toBe(EstadoRecordatorio::Pendiente)
        ->and($recordatorio->notificar_en->format('Y-m-d H:i'))->toBe('2026-09-10 09:00');
    $this->assertDatabaseHas('auditorias', ['causa_id' => $causa->id, 'accion' => 'RECORDATORIO_CREADO']);
});

test('un abogado no puede abrir recordatorios de una causa ajena ni reasignarlos', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    $causaAjena = Causa::factory()->create(['responsable_id' => $otroAbogado->id]);
    $propia = Causa::factory()->create(['responsable_id' => $abogado->id]);

    Livewire::actingAs($abogado)->test('causas.recordatorios', ['causa' => $causaAjena])->assertForbidden();
    $this->actingAs($abogado);
    expect(fn () => app(RecordatorioService::class)->create($propia, $abogado, [
        'titulo' => 'TAREA', 'descripcion' => null, 'fecha_hora' => CarbonImmutable::now()->addDay(),
        'recordar_minutos_antes' => 0, 'user_id' => $otroAbogado->id,
    ]))->toThrow(AuthorizationException::class);
});

test('un rol personalizado con permiso puede asignar recordatorios a otro usuario', function () {
    $role = Role::create(['name' => 'COORDINADOR', 'guard_name' => 'web']);
    $role->syncPermissions([
        Permiso::CausasVer->value,
        Permiso::CausasVerTodas->value,
        Permiso::RecordatoriosCrear->value,
        Permiso::RecordatoriosAsignar->value,
    ]);
    $asignador = User::factory()->create();
    $destinatario = User::factory()->create();
    $asignador->syncRoles([$role]);
    $destinatario->syncRoles([$role]);
    $causa = Causa::factory()->create();
    $this->actingAs($asignador);

    $recordatorio = app(RecordatorioService::class)->create($causa, $asignador, [
        'titulo' => 'TAREA ASIGNADA',
        'descripcion' => null,
        'fecha_hora' => CarbonImmutable::now()->addDay(),
        'recordar_minutos_antes' => 60,
        'user_id' => $destinatario->id,
    ]);

    expect($recordatorio->user_id)->toBe($destinatario->id);
});

test('reprogramar un recordatorio futuro reinicia la marca de notificación y se audita', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $recordatorio = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $abogado->id, 'created_by' => $abogado->id, 'notificado_at' => now()]);
    $this->actingAs($abogado);

    app(RecordatorioService::class)->update($recordatorio, $abogado, [
        'titulo' => 'TAREA REPROGRAMADA', 'descripcion' => null, 'fecha_hora' => CarbonImmutable::now()->addDays(2),
        'recordar_minutos_antes' => 60, 'user_id' => $abogado->id,
    ]);

    expect($recordatorio->refresh()->notificado_at)->toBeNull();
    $this->assertDatabaseHas('auditorias', ['causa_id' => $causa->id, 'accion' => 'RECORDATORIO_REPROGRAMADO']);
});

test('un recordatorio puede abrirse para edición sin cargar su causa de forma diferida', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $recordatorio = Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
    ]);

    Livewire::actingAs($abogado)
        ->test('causas.recordatorios', ['causa' => $causa])
        ->call('openEditModal', $recordatorio->id)
        ->assertSet('editingId', $recordatorio->id)
        ->assertHasNoErrors();
});

test('los recordatorios pendientes incorporan control de vencimiento en el navegador', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
        'estado' => EstadoRecordatorio::Pendiente,
        'fecha_hora' => now()->addMinute(),
    ]);

    Livewire::actingAs($abogado)
        ->test('causas.recordatorios', ['causa' => $causa])
        ->assertSeeHtml('actualizarVencimiento')
        ->assertSeeHtml('window.setInterval');
});

test('el panel de la causa muestra sólo los recordatorios pendientes', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
        'titulo' => 'RECORDATORIO PENDIENTE',
        'estado' => EstadoRecordatorio::Pendiente,
    ]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
        'titulo' => 'RECORDATORIO COMPLETADO',
        'estado' => EstadoRecordatorio::Completado,
    ]);

    Livewire::actingAs($abogado)
        ->test('causas.recordatorios', ['causa' => $causa])
        ->assertSee('RECORDATORIO PENDIENTE')
        ->assertDontSee('RECORDATORIO COMPLETADO');
});

test('el historial muestra los recordatorios completados y cancelados en un modal', function () {
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
        'titulo' => 'TAREA COMPLETADA',
        'estado' => EstadoRecordatorio::Completado,
    ]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $abogado->id,
        'created_by' => $abogado->id,
        'titulo' => 'TAREA CANCELADA',
        'estado' => EstadoRecordatorio::Cancelado,
    ]);

    Livewire::actingAs($abogado)
        ->test('causas.recordatorios', ['causa' => $causa])
        ->call('openHistoryModal')
        ->assertSet('showHistoryModal', true)
        ->assertSee('TAREA COMPLETADA')
        ->assertSee('TAREA CANCELADA');
});

test('se puede completar o cancelar un recordatorio pendiente según el permiso', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    $completar = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $administrador->id, 'created_by' => $administrador->id]);
    $cancelar = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $administrador->id, 'created_by' => $administrador->id]);
    $this->actingAs($administrador);

    app(RecordatorioService::class)->complete($completar, $administrador);
    app(RecordatorioService::class)->cancel($cancelar, $administrador);

    expect($completar->refresh()->estado)->toBe(EstadoRecordatorio::Completado)
        ->and($cancelar->refresh()->estado)->toBe(EstadoRecordatorio::Cancelado);
});

test('el dashboard personal muestra sólo los próximos recordatorios del usuario autenticado', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);
    Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $abogado->id, 'created_by' => $abogado->id, 'titulo' => 'MI PLAZO', 'fecha_hora' => now()->addDay()]);
    Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $otroAbogado->id, 'created_by' => $abogado->id, 'titulo' => 'PLAZO AJENO', 'fecha_hora' => now()->addDay()]);

    Livewire::actingAs($abogado)->test('pages::dashboard')->assertSee('MI PLAZO')->assertDontSee('PLAZO AJENO');
});
