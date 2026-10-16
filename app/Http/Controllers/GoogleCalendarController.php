<?php

namespace App\Http\Controllers;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoogleCalendarController extends Controller
{
    private function getGoogleClient()
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->addScope(GoogleCalendar::CALENDAR);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        return $client;
    }

    public function redirectToGoogle()
    {
        $client = $this->getGoogleClient();

        return redirect()->away($client->createAuthUrl());
    }

    public function handleGoogleCallback(Request $request)
    {
        if (! $request->has('code')) {
            return redirect()->route('dashboard')->with('error', 'No se otorgaron permisos de Google.');
        }

        $client = $this->getGoogleClient();
        $token = $client->fetchAccessTokenWithAuthCode($request->get('code'));

        if (isset($token['error'])) {
            return redirect()->route('dashboard')->with('error', 'Error al conectar con Google.');
        }

        $user = Auth::user();
        $user->google_access_token = json_encode($token);
        if (isset($token['refresh_token'])) {
            $user->google_refresh_token = $token['refresh_token'];
        }
        $user->google_token_expires_at = now()->addSeconds($token['expires_in'] ?? 3600);
        $user->google_calendar_connected = true;
        $user->save();

        return redirect()->route('dashboard')->with('success', '¡Google Calendar conectado exitosamente!');
    }
}
