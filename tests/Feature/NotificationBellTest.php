<?php

use App\Models\Causa;
use App\Models\Recordatorio;
use App\Models\User;
use App\Notifications\RecordatorioPendienteNotification;
use Livewire\Livewire;

test('la campana muestra y marca como leídas sólo las notificaciones del usuario', function () {
    $usuario = User::factory()->abogado()->create();
    $otroUsuario = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);
    $recordatorio = Recordatorio::factory()->create(['causa_id' => $causa->id, 'user_id' => $usuario->id, 'created_by' => $usuario->id, 'titulo' => 'PLAZO PERSONAL']);
    $usuario->notify(new RecordatorioPendienteNotification($recordatorio->load('causa')));
    $otroUsuario->notify(new RecordatorioPendienteNotification($recordatorio));
    $notification = $usuario->unreadNotifications()->firstOrFail();

    Livewire::actingAs($usuario)->test('notifications.bell')->assertSee('PLAZO PERSONAL')->call('markAsRead', $notification->id)->assertSet('unreadCount', 0);

    expect($notification->refresh()->read_at)->not->toBeNull()->and($otroUsuario->unreadNotifications()->count())->toBe(1);
});

test('la campana incorpora avisos inmediatos para recordatorios pendientes', function () {
    $usuario = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $usuario->id,
        'created_by' => $usuario->id,
        'titulo' => 'AVISO INMEDIATO',
        'fecha_hora' => now()->addHour(),
        'notificar_en' => now()->subMinute(),
    ]);

    Livewire::actingAs($usuario)
        ->test('notifications.bell')
        ->assertSee('AVISO INMEDIATO')
        ->assertSeeHtml('actualizarAvisos')
        ->assertSeeHtml('window.setInterval');
});

test('la campana omite recordatorios cuyas causas fueron eliminadas', function () {
    $usuario = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);
    Recordatorio::factory()->create([
        'causa_id' => $causa->id,
        'user_id' => $usuario->id,
        'created_by' => $usuario->id,
        'titulo' => 'RECORDATORIO HUÉRFANO',
        'fecha_hora' => now()->addHour(),
        'notificar_en' => now()->subMinute(),
    ]);
    $causa->delete();

    Livewire::actingAs($usuario)
        ->test('notifications.bell')
        ->assertDontSee('RECORDATORIO HUÉRFANO');
});
