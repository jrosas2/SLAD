<?php

namespace App\Notifications;

use App\Models\Recordatorio;
use Illuminate\Notifications\Notification;

class RecordatorioPendienteNotification extends Notification
{
    public function __construct(public readonly Recordatorio $recordatorio) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /** @return array<string, int|string|null> */
    public function toDatabase(object $notifiable): array
    {
        $this->recordatorio->loadMissing('causa:id,numero_causa,nombre');

        return [
            'recordatorio_id' => $this->recordatorio->id,
            'causa_id' => $this->recordatorio->causa_id,
            'causa_numero' => $this->recordatorio->causa->numero_causa,
            'titulo' => 'Recordatorio de causa',
            'mensaje' => $this->recordatorio->titulo,
            'fecha_hora' => $this->recordatorio->fecha_hora->toIso8601String(),
            'url' => route('causas.show', $this->recordatorio->causa),
        ];
    }
}
