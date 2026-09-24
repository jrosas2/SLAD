<?php

use App\Enums\EstadoRecordatorio;
use App\Models\Causa;
use App\Models\Recordatorio;
use App\Models\User;
use App\Notifications\RecordatorioPendienteNotification;
use Illuminate\Support\Facades\Notification;

test('el comando notifica recordatorios vencidos o dentro de su anticipación una sola vez', function () {
    Notification::fake();
    $usuario = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);
    $pendiente = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $usuario->id, 'created_by' => $usuario->id, 'notificar_en' => now()->subMinute()]);
    $futuro = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $usuario->id, 'created_by' => $usuario->id, 'notificar_en' => now()->addMinute()]);

    $this->artisan('recordatorios:procesar')->assertSuccessful();
    $this->artisan('recordatorios:procesar')->assertSuccessful();

    Notification::assertSentTo($usuario, RecordatorioPendienteNotification::class, 1);
    expect($pendiente->refresh()->notificado_at)->not->toBeNull()->and($futuro->refresh()->notificado_at)->toBeNull();
});

test('el comando omite recordatorios completados y cancelados', function () {
    Notification::fake();
    $usuario = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);
    Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $usuario->id, 'created_by' => $usuario->id, 'estado' => EstadoRecordatorio::Completado, 'notificar_en' => now()->subMinute()]);
    Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $usuario->id, 'created_by' => $usuario->id, 'estado' => EstadoRecordatorio::Cancelado, 'notificar_en' => now()->subMinute()]);

    $this->artisan('recordatorios:procesar')->assertSuccessful();
    Notification::assertNothingSent();
});
