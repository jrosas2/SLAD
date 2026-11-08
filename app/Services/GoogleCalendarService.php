<?php

namespace App\Services;

use App\Enums\EstadoRecordatorio;
use App\Models\GoogleCalendarConnection;
use App\Models\PjudMovimiento;
use App\Models\PjudProgramacion;
use App\Models\Recordatorio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    public function sync(Recordatorio $recordatorio): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if ($recordatorio->estado !== EstadoRecordatorio::Pendiente) {
            $this->delete($recordatorio);

            return;
        }

        $response = $recordatorio->google_calendar_event_id === null
            ? $this->request()->post($this->eventsUrl(), $this->eventPayload($recordatorio))
            : $this->request()->patch($this->eventUrl($recordatorio->google_calendar_event_id), $this->eventPayload($recordatorio));

        $eventId = $response->throw()->json('id');

        if (! is_string($eventId) || blank($eventId)) {
            throw new RuntimeException('Google Calendar no devolvió el identificador del evento.');
        }

        if ($recordatorio->google_calendar_event_id === null) {
            $recordatorio->forceFill(['google_calendar_event_id' => $eventId])->save();
        }
    }

    /**
     * Crea el evento "🚩 Actividad P. Judicial" para un movimiento detectado
     * en el Estado Diario del PJUD. Devuelve el id del evento, o null si
     * Google Calendar no está configurado.
     */
    public function crearEventoActividadJudicial(PjudMovimiento $movimiento): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $eventId = $this->request()
            ->post($this->eventsUrl(), $this->actividadJudicialPayload($movimiento))
            ->throw()
            ->json('id');

        if (! is_string($eventId) || blank($eventId)) {
            throw new RuntimeException('Google Calendar no devolvió el identificador del evento.');
        }

        return $eventId;
    }

    /**
     * Crea el evento "⚖️ Causa en tabla" el día de la vista de una causa
     * detectada en la programación de salas de una Corte. Devuelve el id del
     * evento, o null si Google Calendar no está configurado.
     */
    public function crearEventoProgramacionPjud(PjudProgramacion $programacion): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $eventId = $this->request()
            ->post($this->eventsUrl(), $this->programacionPayload($programacion))
            ->throw()
            ->json('id');

        if (! is_string($eventId) || blank($eventId)) {
            throw new RuntimeException('Google Calendar no devolvió el identificador del evento.');
        }

        return $eventId;
    }

    /**
     * Crea el evento de status del monitoreo PJUD (inicio o cierre del día).
     * Devuelve el id del evento, o null si Google Calendar no está configurado.
     */
    public function crearEventoStatusMonitoreoPjud(string $titulo, string $descripcion): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $config = (array) config('pjud.notificacion');
        $inicio = now()->addMinutes(max(1, (int) ($config['minutos_hasta_evento'] ?? 2)));
        $alerta = max(0, (int) ($config['alerta_minutos_antes'] ?? 1));

        $attendees = collect((array) ($config['correos_adicionales'] ?? []))
            ->map(fn (string $correo) => mb_strtolower(trim($correo)))
            ->filter(fn (string $correo) => filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->map(fn (string $correo) => ['email' => $correo])
            ->values()
            ->all();

        $eventId = $this->request()
            ->post($this->eventsUrl(), [
                'summary' => $titulo,
                'description' => $descripcion,
                'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => config('app.timezone')],
                'end' => ['dateTime' => $inicio->addMinutes(15)->toRfc3339String(), 'timeZone' => config('app.timezone')],
                'attendees' => $attendees,
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'email', 'minutes' => $alerta],
                        ['method' => 'popup', 'minutes' => $alerta],
                    ],
                ],
            ])
            ->throw()
            ->json('id');

        if (! is_string($eventId) || blank($eventId)) {
            throw new RuntimeException('Google Calendar no devolvió el identificador del evento.');
        }

        return $eventId;
    }

    /** @return array<string, mixed> */
    private function programacionPayload(PjudProgramacion $programacion): array
    {
        $programacion->loadMissing(['causa.responsable:id,name,email', 'causa.juzgado:id,nombre']);
        $causa = $programacion->causa;
        $config = (array) config('pjud.programacion.notificacion');

        // La tabla no informa la hora de la vista: el evento va a la hora configurada.
        [$hora, $minuto] = array_map('intval', array_pad(explode(':', (string) ($config['hora_evento'] ?? '09:00')), 2, 0));
        $fechaVista = CarbonImmutable::parse($programacion->fecha_vista);
        $inicio = CarbonImmutable::parse($fechaVista->toDateString(), config('app.timezone'))
            ->setTime(max(0, min(23, $hora)), max(0, min(59, $minuto)));
        $fin = $inicio->addMinutes(max(1, (int) ($config['duracion_minutos'] ?? 60)));
        $alerta = max(0, min(40320, (int) ($config['alerta_minutos_antes'] ?? 1440)));

        $correos = (array) ($config['correos_adicionales'] ?? []);
        if (($config['invitar_responsable'] ?? true) && filled($causa?->responsable?->email)) {
            array_unshift($correos, $causa->responsable->email);
        }

        $attendees = collect($correos)
            ->map(fn (string $correo) => mb_strtolower(trim($correo)))
            ->filter(fn (string $correo) => filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->map(fn (string $correo) => ['email' => $correo])
            ->values()
            ->all();

        // Si la causa ya estaba en otra tabla (cambió de fecha o de sala), se informa.
        $anteriores = PjudProgramacion::query()
            ->where('causa_id', $programacion->causa_id)
            ->where('id', '!=', $programacion->id)
            ->where('tribunal_pjud_codigo', $programacion->tribunal_pjud_codigo)
            ->orderByDesc('fecha_vista')
            ->limit(3)
            ->get(['fecha_vista', 'sala'])
            ->map(fn (PjudProgramacion $anterior) => CarbonImmutable::parse($anterior->fecha_vista)->format('d-m-Y').' · Sala '.$anterior->sala)
            ->implode('; ');

        $sala = "Sala {$programacion->sala}";

        return [
            'summary' => trim((string) ($config['asunto'] ?? '⚖️ Causa en tabla'))." · {$programacion->numero_ingreso} · {$sala}",
            'description' => collect([
                "N° de ingreso: {$programacion->numero_ingreso}",
                $programacion->tipo_coincidencia === 'parcial'
                    ? "⚠️ Coincidencia parcial: en SLAD figura \"{$causa?->numero_causa}\" y el PJUD informa \"{$programacion->numero_ingreso}\". Verifique que sea la misma causa."
                    : null,
                "Corte: {$programacion->tribunal_nombre}",
                'Fecha de la vista: '.$fechaVista->format('d-m-Y'),
                "{$sala} · Tabla {$programacion->tipo_tabla}".($programacion->numero_orden !== null ? " · N° {$programacion->numero_orden} en la tabla" : ''),
                filled($programacion->relator) ? "Relator: {$programacion->relator}" : null,
                $causa !== null ? "Causa SLAD: {$causa->nombre}" : null,
                filled($programacion->caratulado) ? "Carátula PJUD: {$programacion->caratulado}" : null,
                $anteriores !== '' ? "Programación anterior: {$anteriores}" : null,
                'La hora del evento es referencial: la tabla del PJUD no informa la hora de la vista. Constitución de sala provisional y sujeta a revisión el día de la vista (art. 90 COT).',
                $causa !== null ? 'Ver en SLAD: '.route('causas.show', $causa) : null,
            ])->filter()->implode("\n"),
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => config('app.timezone')],
            'end' => ['dateTime' => $fin->toRfc3339String(), 'timeZone' => config('app.timezone')],
            'attendees' => $attendees,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => $alerta],
                    ['method' => 'popup', 'minutes' => $alerta],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function actividadJudicialPayload(PjudMovimiento $movimiento): array
    {
        $movimiento->loadMissing(['causa.responsable:id,name,email', 'causa.juzgado:id,nombre']);
        $causa = $movimiento->causa;
        $config = (array) config('pjud.notificacion');

        $inicio = now()->addMinutes(max(1, (int) ($config['minutos_hasta_evento'] ?? 2)));
        $fin = $inicio->addMinutes(max(1, (int) ($config['duracion_minutos'] ?? 15)));
        $alerta = max(0, (int) ($config['alerta_minutos_antes'] ?? 1));

        $correos = (array) ($config['correos_adicionales'] ?? []);
        if (($config['invitar_responsable'] ?? true) && filled($causa?->responsable?->email)) {
            array_unshift($correos, $causa->responsable->email);
        }

        $attendees = collect($correos)
            ->map(fn (string $correo) => mb_strtolower(trim($correo)))
            ->filter(fn (string $correo) => filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->map(fn (string $correo) => ['email' => $correo])
            ->values()
            ->all();

        // Número oficial del PJUD (completo), aunque en SLAD esté incompleto.
        $numeroCausa = $movimiento->numero_ingreso ?: (string) $causa?->numero_causa;

        return [
            'summary' => (string) ($config['asunto'] ?? '🚩 Actividad P. Judicial'),
            'description' => collect([
                "N° de causa: {$numeroCausa}",
                $movimiento->tipo_coincidencia === 'parcial'
                    ? "⚠️ Coincidencia parcial: en SLAD figura \"{$causa?->numero_causa}\" y el PJUD informa \"{$movimiento->numero_ingreso}\". Verifique que sea la misma causa."
                    : null,
                "Tribunal: {$movimiento->tribunal_nombre}",
                $causa !== null ? "Causa SLAD: {$causa->nombre}" : null,
                filled($movimiento->caratulado) ? "Partes: {$movimiento->caratulado}" : null,
                filled($movimiento->tramite)
                    ? (is_numeric($movimiento->tramite) ? "Providencias del día: {$movimiento->tramite}" : "Trámite: {$movimiento->tramite}")
                    : null,
                filled($movimiento->detalle) ? "Detalle PJUD: {$movimiento->detalle}" : null,
                'Estado Diario del '.CarbonImmutable::parse($movimiento->fecha_estado_diario)->format('d-m-Y'),
                $causa !== null ? 'Ver en SLAD: '.route('causas.show', $causa) : null,
            ])->filter()->implode("\n"),
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => config('app.timezone')],
            'end' => ['dateTime' => $fin->toRfc3339String(), 'timeZone' => config('app.timezone')],
            'attendees' => $attendees,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => $alerta],
                    ['method' => 'popup', 'minutes' => $alerta],
                ],
            ],
        ];
    }

    private function delete(Recordatorio $recordatorio): void
    {
        if ($recordatorio->google_calendar_event_id === null) {
            return;
        }

        $response = $this->request()->delete($this->eventUrl($recordatorio->google_calendar_event_id));

        if (! $response->notFound()) {
            $response->throw();
        }

        $recordatorio->forceFill(['google_calendar_event_id' => null])->save();
    }

    /** @return array<string, mixed> */
    private function eventPayload(Recordatorio $recordatorio): array
    {
        $recordatorio->loadMissing(['causa:id,numero_causa,nombre', 'usuario:id,name,email', 'creador:id,name,email']);
        $duration = max(1, (int) config('services.google_calendar.event_duration_minutes', 30));
        $attendees = [];

        foreach ([$recordatorio->usuario, $recordatorio->google_calendar_incluir_creador ? $recordatorio->creador : null] as $user) {
            if (! $user instanceof User || blank($user->email) || in_array($user->email, array_column($attendees, 'email'), true)) {
                continue;
            }

            $attendees[] = ['email' => $user->email];
        }

        return [
            'summary' => "[SLAD] {$recordatorio->titulo}",
            'description' => $this->description($recordatorio),
            'start' => [
                'dateTime' => $recordatorio->fecha_hora->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
            'end' => [
                'dateTime' => $recordatorio->fecha_hora->addMinutes($duration)->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
            'attendees' => $attendees,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [[
                    'method' => 'popup',
                    'minutes' => $recordatorio->recordar_minutos_antes ?? 0,
                ]],
            ],
        ];
    }

    private function description(Recordatorio $recordatorio): string
    {
        $causa = $recordatorio->causa;
        $causeLabel = filled($causa->numero_causa)
            ? "Causa: {$causa->numero_causa} — {$causa->nombre}"
            : "Causa: {$causa->nombre}";

        return collect([$causeLabel, $recordatorio->descripcion, route('causas.show', $causa)])
            ->filter(fn (?string $line) => filled($line))
            ->implode("\n\n");
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 500, 1000]);
    }

    private function accessToken(): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 500, 1000])
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'refresh_token' => $this->refreshToken(),
                'grant_type' => 'refresh_token',
            ]);

        $accessToken = $response->throw()->json('access_token');

        if (! is_string($accessToken) || blank($accessToken)) {
            throw new RuntimeException('Google no devolvió un token de acceso para Calendar.');
        }

        return $accessToken;
    }

    private function isConfigured(): bool
    {
        return config('services.google_calendar.enabled')
            && filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && (GoogleCalendarConnection::query()->exists() || filled(config('services.google_calendar.refresh_token')));
    }

    private function eventsUrl(): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($this->calendarId()).'/events?sendUpdates=all';
    }

    private function eventUrl(string $eventId): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($this->calendarId()).'/events/'.rawurlencode($eventId).'?sendUpdates=all';
    }

    private function calendarId(): string
    {
        return GoogleCalendarConnection::query()->value('calendar_id') ?? (string) config('services.google_calendar.calendar_id');
    }

    private function refreshToken(): ?string
    {
        $connection = GoogleCalendarConnection::query()->first();

        if ($connection === null) {
            return config('services.google_calendar.refresh_token');
        }

        return $connection->refresh_token;
    }
}
