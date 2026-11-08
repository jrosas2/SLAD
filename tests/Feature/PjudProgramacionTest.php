<?php

use App\Jobs\ConsultarProgramacionCorte;
use App\Jobs\NotificarProgramacionPjudGoogleCalendar;
use App\Models\Causa;
use App\Models\EstadoCausa;
use App\Models\Juzgado;
use App\Models\PjudProgramacion;
use App\Models\TribunalPjud;
use App\Models\User;
use App\Services\Pjud\EntradaProgramacion;
use App\Services\Pjud\FuenteProgramacionSalas;
use App\Services\Pjud\ProgramacionSalasPjudClient;
use App\Support\Pjud\NormalizadorPjud;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function cortePjud(int $codigo, string $nombre, int $tipo = 2): TribunalPjud
{
    return TribunalPjud::query()->create([
        'codigo' => $codigo,
        'tipo_juzgado' => $tipo,
        'nombre' => $nombre,
        'nombre_normalizado' => NormalizadorPjud::nombreTribunal($nombre),
    ]);
}

function causaEnCorte(TribunalPjud $corte, string $numero, string $estado = 'VIGENTE', array $atributos = []): Causa
{
    return Causa::factory()->create([
        'estado_causa_id' => EstadoCausa::query()->firstOrCreate(['nombre' => $estado])->id,
        'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $corte->codigo])->id,
        'numero_causa' => $numero,
        ...$atributos,
    ]);
}

function entradaTabla(string $ingreso, string $fecha = '2026-11-09', string $sala = '1'): EntradaProgramacion
{
    return new EntradaProgramacion('3', 'ordinaria', CarbonImmutable::parse($fecha), $sala, $sala === '1' ? 'PRIMERA' : 'SEGUNDA', $ingreso, 'PÉREZ CON FISCO', 4, 'RELATOR DE PRUEBA');
}

/** @param list<EntradaProgramacion> $entradas */
function fuenteProgramacionFalsa(array $entradas): void
{
    app()->instance(FuenteProgramacionSalas::class, new class($entradas) implements FuenteProgramacionSalas
    {
        public function __construct(private array $entradas) {}

        public function obtener(TribunalPjud $corte, CarbonImmutable $desde): array
        {
            return $this->entradas;
        }
    });
}

beforeEach(function () {
    config()->set('pjud.programacion.enabled', true);
    config()->set('pjud.programacion.horario', ['hora_inicio' => 14, 'hora_fin' => 20, 'frecuencia_minutos' => 60]);
    config()->set('pjud.programacion.segundos_entre_consultas', 0);
    config()->set('pjud.programacion.segundos_entre_cortes', 0);
    config()->set('pjud.programacion.estados_causa_consultables', ['VIGENTE']);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 15:00:00'));
});

test('consulta sólo las Cortes donde SLAD tiene causas vigentes, de cualquier jurisdicción', function () {
    Queue::fake();
    $temuco = cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO');
    $concepcion = cortePjud(4551013, 'CORTE DE APELACIONES DE CONCEPCIÓN');
    $sinVigentes = cortePjud(6051007, 'CORTE DE APELACIONES DE SANTIAGO');
    $juzgadoCivil = cortePjud(4903101, '1º JUZGADO CIVIL DE TEMUCO', 3);
    causaEnCorte($temuco, '1436-2026');
    causaEnCorte($concepcion, '200-2026');
    causaEnCorte($sinVigentes, '300-2026', 'CERRADA');
    causaEnCorte($juzgadoCivil, 'C-10-2026'); // primera instancia: no publica tablas

    $this->artisan('pjud:revisar-programacion')->assertSuccessful();

    Queue::assertPushed(ConsultarProgramacionCorte::class, 2);
    Queue::assertPushed(ConsultarProgramacionCorte::class, fn ($job) => $job->tribunalCodigo === $temuco->codigo);
    Queue::assertPushed(ConsultarProgramacionCorte::class, fn ($job) => $job->tribunalCodigo === $concepcion->codigo);
});

test('usa su propio horario, distinto al del Estado Diario', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00'));
    causaEnCorte(cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO'), '1436-2026');

    $this->artisan('pjud:revisar-programacion')->assertSuccessful();
    Queue::assertNothingPushed();

    config()->set('pjud.programacion.horario.hora_inicio', 9);
    $this->artisan('pjud:revisar-programacion')->assertSuccessful();
    Queue::assertPushed(ConsultarProgramacionCorte::class, 1);
});

test('no consulta nada si la programación está desactivada', function () {
    Queue::fake();
    config()->set('pjud.programacion.enabled', false);
    causaEnCorte(cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO'), '1436-2026');

    $this->artisan('pjud:revisar-programacion --forzar')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('calza el número de ingreso ignorando espacios y no duplica la misma vista', function () {
    Queue::fake([NotificarProgramacionPjudGoogleCalendar::class]);
    $corte = cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO');
    $conEspacios = causaEnCorte($corte, '1436 -2026');
    $sinEspacios = causaEnCorte($corte, 'Rol 183-2026');
    causaEnCorte($corte, '9999-2026');

    fuenteProgramacionFalsa([
        entradaTabla('1436 - 2026'),
        entradaTabla('183 – 2026', '2026-11-10', '2'),
        entradaTabla('183 - 2025'), // mismo número, otro año: no calza
    ]);

    dispatch_sync(new ConsultarProgramacionCorte($corte->codigo));
    dispatch_sync(new ConsultarProgramacionCorte($corte->codigo)); // siguiente revisión

    expect(PjudProgramacion::query()->count())->toBe(2)
        ->and(PjudProgramacion::query()->where('causa_id', $conEspacios->id)->first())
        ->fecha_vista->toDateString()->toBe('2026-11-09')
        ->sala->toBe('PRIMERA')
        ->tipo_coincidencia->toBe('exacta')
        ->detectada_en->not->toBeNull()
        ->and(PjudProgramacion::query()->where('causa_id', $sinEspacios->id)->value('sala_numero'))->toBe('2');
    Queue::assertPushed(NotificarProgramacionPjudGoogleCalendar::class, 2);
});

test('si la causa cambia de fecha o de sala, se avisa de nuevo', function () {
    Queue::fake([NotificarProgramacionPjudGoogleCalendar::class]);
    $corte = cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO');
    causaEnCorte($corte, '1436-2026');

    fuenteProgramacionFalsa([entradaTabla('1436 - 2026', '2026-11-09', '1')]);
    dispatch_sync(new ConsultarProgramacionCorte($corte->codigo));

    fuenteProgramacionFalsa([entradaTabla('1436 - 2026', '2026-11-12', '2')]);
    dispatch_sync(new ConsultarProgramacionCorte($corte->codigo));

    expect(PjudProgramacion::query()->count())->toBe(2);
    Queue::assertPushed(NotificarProgramacionPjudGoogleCalendar::class, 2);
});

test('sólo cruza causas de la Corte consultada y en estados configurados', function () {
    Queue::fake([NotificarProgramacionPjudGoogleCalendar::class]);
    $temuco = cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO');
    $santiago = cortePjud(6051007, 'CORTE DE APELACIONES DE SANTIAGO');
    causaEnCorte($santiago, '1436-2026'); // mismo número, otra Corte
    causaEnCorte($temuco, '1436-2026', 'CERRADA');

    fuenteProgramacionFalsa([entradaTabla('1436 - 2026')]);
    dispatch_sync(new ConsultarProgramacionCorte($temuco->codigo));

    expect(PjudProgramacion::query()->count())->toBe(0);

    config()->set('pjud.programacion.estados_causa_consultables', ['VIGENTE', 'CERRADA']);
    dispatch_sync(new ConsultarProgramacionCorte($temuco->codigo));

    expect(PjudProgramacion::query()->count())->toBe(1);
});

test('el cliente recorre tipos de tabla, salas y constitución con la condición de cada Corte', function () {
    config()->set('pjud.programacion.url_sesion', null);
    $suprema = cortePjud(6050000, 'CORTE SUPREMA', 1);

    Http::fake([
        '*/getTypeTables/' => Http::response(['status' => true, 'data' => ['typesTable' => [
            ['id' => '0', 'value' => '...'],
            ['id' => '3', 'value' => 'ORDINARIA                     '],
        ]]]),
        '*/getDataTypeTableSelectedML/' => Http::response(
            '<table><thead><tr><th>Fecha</th><th>Sala</th><th>Relator</th></tr></thead><tbody>'
            ."<tr><td>07/11/2026</td><td><a onclick=\"linkConstitucionSala('1','6050000','3','07/11/2026','');\">PRIMERA</a></td><td>X</td></tr>"
            ."<tr><td>09/11/2026</td><td><a onclick=\"linkConstitucionSala('3','6050000','3','09/11/2026','');\">TERCERA</a></td><td>Y</td></tr>"
            .'</tbody></table>'
        ),
        '*/constitutionOfRoomML/' => Http::response(
            '<table><thead><tr><th>N°</th><th>Causa</th><th>Ingreso</th></tr></thead><tbody>'
            .'<tr><td>1</td><td>KRAAF / CMF</td><td>55754 - 2026</td></tr></tbody></table>'
        ),
    ]);

    $entradas = app(ProgramacionSalasPjudClient::class)->obtener($suprema, CarbonImmutable::parse('2026-11-08'));

    expect($entradas)->toHaveCount(1) // la sala del 07/11 ya pasó: no se consulta
        ->and($entradas[0]->tipoTabla)->toBe('ORDINARIA')
        ->and($entradas[0]->sala)->toBe('TERCERA')
        ->and($entradas[0]->numeroIngreso)->toBe('55754 - 2026');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/getTypeTables/')
        && collect($request->data())->contains(fn ($campo) => ($campo['name'] ?? null) === 'condicion' && $campo['contents'] === 'COSUP'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/constitutionOfRoomML/')
        && collect($request->data())->contains(fn ($campo) => ($campo['name'] ?? null) === 'fecha' && $campo['contents'] === '09/11/2026'));
    Http::assertSentCount(3);
});

test('crea el evento ⚖️ Causa en tabla el día de la vista, con alerta e invitación al responsable', function () {
    config()->set('services.google_calendar', [
        'enabled' => true,
        'calendar_id' => 'slad@empresa.cl',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'event_duration_minutes' => 30,
    ]);
    config()->set('pjud.programacion.notificacion.hora_evento', '09:00');
    config()->set('pjud.programacion.notificacion.alerta_minutos_antes', 1440);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evento-tabla-1']),
    ]);
    $corte = cortePjud(4901014, 'CORTE DE APELACIONES DE TEMUCO');
    $responsable = User::factory()->abogado()->create(['email' => 'abogado@empresa.cl']);
    $causa = causaEnCorte($corte, '1436-2026', 'VIGENTE', ['responsable_id' => $responsable->id]);
    Queue::fake([NotificarProgramacionPjudGoogleCalendar::class]);
    fuenteProgramacionFalsa([entradaTabla('1436 - 2026', '2026-11-10', '2')]);
    dispatch_sync(new ConsultarProgramacionCorte($corte->codigo));
    $programacion = PjudProgramacion::query()->firstOrFail();

    dispatch_sync(new NotificarProgramacionPjudGoogleCalendar($programacion->id));

    expect($programacion->fresh())
        ->google_calendar_event_id->toBe('evento-tabla-1')
        ->notificado_at->not->toBeNull();

    Http::assertSent(function (Request $request) use ($causa) {
        if (! str_contains($request->url(), '/events')) {
            return false;
        }

        return str_starts_with($request['summary'], '⚖️ Causa en tabla · 1436 - 2026 · Sala SEGUNDA')
            && str_starts_with($request['start']['dateTime'], '2026-11-10T09:00:00')
            && $request['attendees'] === [['email' => 'abogado@empresa.cl']]
            && $request['reminders']['overrides'][0]['minutes'] === 1440
            && str_contains($request['description'], 'Fecha de la vista: 10-11-2026')
            && str_contains($request['description'], route('causas.show', $causa));
    });
});
