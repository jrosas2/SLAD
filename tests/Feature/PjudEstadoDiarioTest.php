<?php

use App\Console\Commands\RevisarEstadoDiarioPjud;
use App\Console\Commands\StatusMonitoreoPjud;
use App\Jobs\ConsultarEstadoDiarioTribunal;
use App\Jobs\NotificarMovimientoPjudGoogleCalendar;
use App\Models\Causa;
use App\Models\EstadoCausa;
use App\Models\Juzgado;
use App\Models\PjudMovimiento;
use App\Models\TribunalPjud;
use App\Models\User;
use App\Services\Pjud\EntradaEstadoDiario;
use App\Services\Pjud\EstadoDiarioPjudClient;
use App\Services\Pjud\FuenteEstadoDiario;
use App\Services\Pjud\VinculadorJuzgadosPjud;
use App\Support\Pjud\NormalizadorPjud;
use App\Support\Pjud\RegistroMonitoreoPjud;
use Carbon\CarbonImmutable;
use Database\Seeders\TribunalPjudSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function tribunalPjud(int $codigo, string $nombre, int $tipo = 6): TribunalPjud
{
    return TribunalPjud::query()->create([
        'codigo' => $codigo,
        'tipo_juzgado' => $tipo,
        'nombre' => $nombre,
        'nombre_normalizado' => NormalizadorPjud::nombreTribunal($nombre, (array) config('pjud.equivalencias_nombre')),
    ]);
}

function estadoCausaId(string $nombre): int
{
    return EstadoCausa::query()->firstOrCreate(['nombre' => $nombre])->id;
}

/** @param list<EntradaEstadoDiario> $entradas */
function fuenteFalsa(array $entradas): void
{
    app()->instance(FuenteEstadoDiario::class, new class($entradas) implements FuenteEstadoDiario
    {
        public function __construct(private array $entradas) {}

        public function obtener(TribunalPjud $tribunal, CarbonImmutable $fecha): array
        {
            return $this->entradas;
        }

        public function detalle(TribunalPjud $tribunal, CarbonImmutable $fecha, EntradaEstadoDiario $entrada): ?string
        {
            return 'Tipo: Principal · Estado: Tramitación';
        }
    });
}

beforeEach(function () {
    config()->set('pjud.enabled', true);
    config()->set('pjud.horario', ['hora_inicio' => 8, 'hora_fin' => 18]);
    config()->set('pjud.estado_diario.segundos_entre_tribunales', 0);
    config()->set('pjud.estado_diario.dias_atras', 0);
    config()->set('pjud.estado_diario.incluir_hoy', true);
});

test('no consulta al PJUD fuera del horario de 8 a 18 horas', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 19:00:00'));
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id, 'numero_causa' => 'C-1-2026']);

    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('consulta sólo los tribunales donde SLAD tiene causas', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00'));
    $conCausas = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    $sinCausas = tribunalPjud(6053102, '2º JUZGADO CIVIL DE SANTIAGO');
    $juzgado = Juzgado::factory()->create(['tribunal_pjud_codigo' => $conCausas->codigo]);
    Juzgado::factory()->create(['tribunal_pjud_codigo' => $sinCausas->codigo]);
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-1234-2025']);

    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();

    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, 1);
    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, fn ($job) => $job->tribunalCodigo === $conCausas->codigo && $job->fecha === '2026-10-16');
});

test('detecta el movimiento sólo cuando calzan número de ingreso y tribunal, sin duplicar', function () {
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    $juzgado = Juzgado::factory()->create(['nombre' => '1º Juzgado Civil de Santiago', 'tribunal_pjud_codigo' => $tribunal->codigo]);
    $causa = Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-1234-2025']);
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-9999-2025']);

    fuenteFalsa([
        new EntradaEstadoDiario('C-01234-2025', '1° Juzgado Civil de Santiago', 'PÉREZ / BANCO', 'Resolución'),
        new EntradaEstadoDiario('C-9999-2025', '2º Juzgado Civil de Santiago', 'OTRO', 'Otro tribunal: no calza'),
        new EntradaEstadoDiario('C-5555-2025', '1º Juzgado Civil de Santiago', 'AJENA', 'No existe en SLAD'),
    ]);

    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));
    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16')); // siguiente hora

    expect(PjudMovimiento::query()->count())->toBe(1)
        ->and(PjudMovimiento::query()->first())
        ->causa_id->toBe($causa->id)
        ->detalle->toBe('Tipo: Principal · Estado: Tramitación');
    Queue::assertPushed(NotificarMovimientoPjudGoogleCalendar::class, 1);
});

test('crea el evento 🚩 Actividad P. Judicial con alerta de 1 minuto y el número de causa', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00'));
    config()->set('services.google_calendar', [
        'enabled' => true,
        'calendar_id' => 'slad@empresa.cl',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'event_duration_minutes' => 30,
    ]);
    config()->set('pjud.notificacion.correos_adicionales', ['jefatura@empresa.cl']);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evento-pjud-1']),
    ]);
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    $responsable = User::factory()->abogado()->create(['email' => 'abogado@empresa.cl']);
    $causa = Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'),
        'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id,
        'responsable_id' => $responsable->id,
        'numero_causa' => 'C-1234-2025',
    ]);
    $movimiento = PjudMovimiento::query()->create([
        'causa_id' => $causa->id,
        'tribunal_pjud_codigo' => $tribunal->codigo,
        'fecha_estado_diario' => '2026-10-16',
        'numero_ingreso' => 'C-1234-2025',
        'tribunal_nombre' => '1º JUZGADO CIVIL DE SANTIAGO',
        'tramite' => 'Resolución',
        'huella' => str_repeat('a', 64),
    ]);

    dispatch_sync(new NotificarMovimientoPjudGoogleCalendar($movimiento->id));

    expect($movimiento->refresh())
        ->google_calendar_event_id->toBe('evento-pjud-1')
        ->notificado_at->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/calendars/slad%40empresa.cl/events?sendUpdates=all')) {
            return false;
        }

        return $request['summary'] === '🚩 Actividad P. Judicial'
            && str_contains($request['description'], 'N° de causa: C-1234-2025')
            && $request['reminders']['overrides'] === [['method' => 'email', 'minutes' => 1], ['method' => 'popup', 'minutes' => 1]]
            && $request['attendees'] === [['email' => 'abogado@empresa.cl'], ['email' => 'jefatura@empresa.cl']];
    });

    // Un segundo intento no crea otro evento.
    dispatch_sync(new NotificarMovimientoPjudGoogleCalendar($movimiento->id));
    Http::assertSentCount(2); // token + evento, una sola vez
});

test('vincula los juzgados de SLAD con el código PJUD por nombre', function () {
    $this->seed(TribunalPjudSeeder::class);
    $civil = Juzgado::factory()->create(['nombre' => '1º Juzgado de Letras en lo Civil de Antofagasta']);
    $coyhaique = Juzgado::factory()->create(['nombre' => 'Juzgado de Garantía de Coyhaique']);
    $sinCodigo = Juzgado::factory()->create(['nombre' => '8º Juzgado del Crimen de San Miguel']);

    $resultado = app(VinculadorJuzgadosPjud::class)->vincular();

    expect($civil->refresh()->tribunal_pjud_codigo)->toBe(2303101)
        ->and($coyhaique->refresh()->tribunal_pjud_codigo)->toBe(5654001)
        ->and($sinCodigo->refresh()->tribunal_pjud_codigo)->toBeNull()
        ->and($resultado['sin_coincidencia']->all())->toContain('8º Juzgado del Crimen de San Miguel');
});

test('por defecto consulta sólo el día anterior porque el PJUD publica con desfase', function () {
    Queue::fake();
    config()->set('pjud.estado_diario.dias_atras', 1);
    config()->set('pjud.estado_diario.incluir_hoy', false);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id, 'numero_causa' => 'C-1-2026']);

    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();

    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, 1);
    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, fn ($job) => $job->fecha === '2026-11-04');
});

test('con PJUD_INCLUIR_HOY revisa hoy y el día anterior', function () {
    Queue::fake();
    config()->set('pjud.estado_diario.dias_atras', 1);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00'));
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id, 'numero_causa' => 'C-1-2026']);

    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();

    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, 2);
    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, fn ($job) => $job->fecha === '2026-10-15');
});

test('consulta el endpoint de estados diarios de pjud.cl con el código del tribunal', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://www.pjud.cl/tribunales/*' => Http::response('ok'),
        'https://www.pjud.cl/ajax/Courts/getDailyStatements' => Http::response(<<<'HTML'
            <div class="panel-heading">Estados diario competencia civil del día 15-10-2026</div>
            <table id="data-table-estado-diario">
                <thead><tr><th>N°</th><th width="200px">Número de Ingreso</th><th>Partes</th><th>Providencias</th></tr></thead>
                <tbody>
                    <tr><td> 1 </td><td><a href="#" data-tipocausa="C" data-rol="1234" data-era="2025" data-codtribunal="6053101" data-tipojuzgado="Z" data-date="15-10-2026" data-codoracle="259">C-1234-2025</a></td><td>PEREZ/BANCO</td><td> 2 </td></tr>
                </tbody>
            </table>
            HTML),
        'https://www.pjud.cl/ajax/Courts/getDailyStatementByCause' => Http::response(
            '<table><thead><tr><th>N°</th><th>Fecha Ingreso</th><th>Tipo</th><th>Estado</th></tr></thead><tbody><tr><td>1</td><td>15/10/2026</td><td>Principal</td><td>Tramitación</td></tr></tbody></table>'
        ),
    ]);
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    $cliente = app(EstadoDiarioPjudClient::class);
    $fecha = CarbonImmutable::parse('2026-10-15');

    $entradas = $cliente->obtener($tribunal, $fecha);

    expect($entradas)->toHaveCount(1)
        ->and($entradas[0]->numeroIngreso)->toBe('C-1234-2025')
        ->and($entradas[0]->caratulado)->toBe('PEREZ/BANCO')
        ->and($entradas[0]->tramite)->toBe('2')
        ->and($entradas[0]->datos['rol'])->toBe('1234')
        ->and($cliente->detalle($tribunal, $fecha, $entradas[0]))->toBe('Fecha Ingreso: 15/10/2026 · Tipo: Principal · Estado: Tramitación');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.pjud.cl/ajax/Courts/getDailyStatements'
        && (string) $request['cod_tribunal'] === '6053101'
        && (string) $request['tipo_juzgado'] === '6'
        && $request['date'] === '15-10-2026');
});

test('las causas cerradas no se consultan ni generan avisos', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00'));
    $soloCerradas = tribunalPjud(6053102, '2º JUZGADO CIVIL DE SANTIAGO');
    $conVigente = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('CERRADA'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $soloCerradas->codigo])->id, 'numero_causa' => 'C-7-2020']);
    $juzgado = Juzgado::factory()->create(['nombre' => '1º Juzgado Civil de Santiago', 'tribunal_pjud_codigo' => $conVigente->codigo]);
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('CERRADA'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-5-2020']);
    Causa::factory()->create(['estado_causa_id' => null, 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-6-2020']);
    $vigente = Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-1234-2025']);

    // El tribunal que sólo tiene causas cerradas no se consulta.
    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();
    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, 1);
    Queue::assertPushed(ConsultarEstadoDiarioTribunal::class, fn ($job) => $job->tribunalCodigo === $conVigente->codigo);

    // En un tribunal consultado, sólo la causa vigente genera movimiento.
    // Se vuelve a simular la cola sólo para el aviso: con Queue::fake() completo, dispatch_sync no ejecuta la consulta.
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    fuenteFalsa([
        new EntradaEstadoDiario('C-5-2020', null, 'CERRADA', '1'),
        new EntradaEstadoDiario('C-6-2020', null, 'SIN ESTADO', '1'),
        new EntradaEstadoDiario('C-1234-2025', null, 'VIGENTE', '1'),
    ]);
    dispatch_sync(new ConsultarEstadoDiarioTribunal($conVigente->codigo, '2026-10-16'));

    expect(PjudMovimiento::query()->pluck('causa_id')->all())->toBe([$vigente->id]);
});

test('reconoce números incompletos de SLAD: compatibles y parciales dentro del mismo tribunal', function () {
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    $tribunal = tribunalPjud(6054001, '1º JUZGADO DE GARANTIA DE SANTIAGO', 14);
    $juzgado = Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo]);
    $vigente = estadoCausaId('VIGENTE');
    $soloNumero = Causa::factory()->create(['estado_causa_id' => $vigente, 'juzgado_id' => $juzgado->id, 'numero_causa' => '5329']);
    $abreviada = Causa::factory()->create(['estado_causa_id' => $vigente, 'juzgado_id' => $juzgado->id, 'numero_causa' => 'O-5329-2025']);
    Causa::factory()->create(['estado_causa_id' => $vigente, 'juzgado_id' => $juzgado->id, 'numero_causa' => '5329-2024']); // otro año
    Causa::factory()->create(['estado_causa_id' => $vigente, 'juzgado_id' => $juzgado->id, 'numero_causa' => 'V-5329-2025']); // otra letra
    Causa::factory()->create([
        'estado_causa_id' => $vigente,
        'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => tribunalPjud(6054002, '2º JUZGADO DE GARANTIA DE SANTIAGO', 14)->codigo])->id,
        'numero_causa' => '5329',
    ]); // mismo número, otro tribunal

    fuenteFalsa([new EntradaEstadoDiario('Ordinaria-5329-2025', null, 'MINISTERIO PUBLICO / X', '1')]);
    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));

    expect(PjudMovimiento::query()->orderBy('causa_id')->pluck('tipo_coincidencia', 'causa_id')->all())
        ->toBe([$soloNumero->id => 'parcial', $abreviada->id => 'compatible']);
});

test('la coincidencia parcial se puede desactivar', function () {
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    config()->set('pjud.coincidencia_parcial', false);
    $tribunal = tribunalPjud(6054001, '1º JUZGADO DE GARANTIA DE SANTIAGO', 14);
    $juzgado = Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo]);
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => '5329']);

    fuenteFalsa([new EntradaEstadoDiario('Ordinaria-5329-2025', null, 'X', '1')]);
    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));

    expect(PjudMovimiento::query()->count())->toBe(0);
});

test('reintenta el aviso de un movimiento que quedó sin notificar en una revisión anterior', function () {
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    $juzgado = Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo]);
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => 'C-1234-2025']);
    fuenteFalsa([new EntradaEstadoDiario('C-1234-2025', null, 'PÉREZ / BANCO', 'Resolución')]);

    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));
    Queue::assertPushed(NotificarMovimientoPjudGoogleCalendar::class, 1);

    // El aviso no llegó a enviarse (notificado_at sigue vacío). Una hora después se reintenta.
    $this->travel(61)->minutes();
    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));
    Queue::assertPushed(NotificarMovimientoPjudGoogleCalendar::class, 2);

    // Ya notificado: no se vuelve a enviar.
    PjudMovimiento::query()->update(['notificado_at' => now()]);
    $this->travel(61)->minutes();
    dispatch_sync(new ConsultarEstadoDiarioTribunal($tribunal->codigo, '2026-10-16'));
    Queue::assertPushed(NotificarMovimientoPjudGoogleCalendar::class, 2);
    expect(PjudMovimiento::query()->count())->toBe(1);
});

test('las consultas de hoy y de ayer del mismo tribunal no se bloquean entre sí', function () {
    $hoy = (new ConsultarEstadoDiarioTribunal(6053101, '2026-10-16'))->middleware()[0];
    $ayer = (new ConsultarEstadoDiarioTribunal(6053101, '2026-10-15'))->middleware()[0];
    $otraVez = (new ConsultarEstadoDiarioTribunal(6053101, '2026-10-16'))->middleware()[0];

    expect($hoy->key)->not->toBe($ayer->key)
        ->and($hoy->key)->toBe($otraVez->key);
});

test('la frecuencia de revisión se configura con PJUD_FRECUENCIA_MINUTOS', function (int $minutos, string $cron) {
    config()->set('pjud.horario.frecuencia_minutos', $minutos);

    expect(RevisarEstadoDiarioPjud::expresionCron())->toBe($cron);
})->with([
    'cada hora (por defecto)' => [60, '0 * * * *'],
    'cada 30 minutos' => [30, '*/30 * * * *'],
    'cada 15 minutos' => [15, '*/15 * * * *'],
    'mínimo 5 minutos' => [1, '*/5 * * * *'],
    'máximo una hora' => [120, '0 * * * *'],
]);

test('consulta la Corte Suprema en su propio servicio y detecta la causa por número y año', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://www.pjud.cl/tribunales/*' => Http::response('ok'),
        'https://www.pjud.cl/ajax/Courts/getDailyStatementsXls' => Http::response([
            ['COD_TRIBUNAL' => 1, 'COD_LIBRO' => 2, 'CRR_INGRESO_RECURSO' => 491, 'FEC_ERA_RECURSO' => 2025, 'CARATULADO' => 'ANDRADE CON FISCO', 'CANT_RESOLUCIONES' => 2],
            ['COD_TRIBUNAL' => 1, 'COD_LIBRO' => 2, 'CRR_INGRESO_RECURSO' => 9999, 'FEC_ERA_RECURSO' => 2025, 'CARATULADO' => 'OTRA', 'CANT_RESOLUCIONES' => 1],
        ]),
    ]);
    Queue::fake([NotificarMovimientoPjudGoogleCalendar::class]);
    $suprema = tribunalPjud(6050000, 'CORTE SUPREMA', 1);
    $juzgado = Juzgado::factory()->create(['nombre' => 'CORTE SUPREMA', 'tribunal_pjud_codigo' => $suprema->codigo]);
    $causa = Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => $juzgado->id, 'numero_causa' => '491-2025']);

    dispatch_sync(new ConsultarEstadoDiarioTribunal($suprema->codigo, '2026-11-03'));

    expect(PjudMovimiento::query()->sole())
        ->causa_id->toBe($causa->id)
        ->tipo_coincidencia->toBe('exacta')
        ->numero_ingreso->toBe('491-2025');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.pjud.cl/ajax/Courts/getDailyStatementsXls'
        && $request['date'] === '03-11-2026');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://www.pjud.cl/ajax/Courts/getDailyStatements');
});

test('la Corte Suprema se vincula automáticamente desde el catálogo', function () {
    $this->seed(TribunalPjudSeeder::class);
    $suprema = Juzgado::factory()->create(['nombre' => 'CORTE SUPREMA']);

    app(VinculadorJuzgadosPjud::class)->vincular();

    expect($suprema->refresh()->tribunal_pjud_codigo)->toBe(6050000);
});

function configurarGoogleCalendarPjud(): void
{
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
        'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response(['id' => 'evento-status-1']),
    ]);
}

test('avisa en Google Calendar el inicio del monitoreo con la fecha que se consulta', function () {
    config()->set('pjud.estado_diario.incluir_hoy', false);
    config()->set('pjud.estado_diario.dias_atras', 1);
    configurarGoogleCalendarPjud();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00:00'));
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id, 'numero_causa' => 'C-1-2026']);

    $this->artisan('pjud:status-monitoreo inicio')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events')
        && $request['summary'] === '✅ Monitoreo PJUD iniciado'
        && str_contains($request['description'], 'Estado Diario que se consulta: 05-11-2026')
        && str_contains($request['description'], 'Tribunales en monitoreo: 1'));
});

test('el cierre resume el día y advierte si no se ejecutó ninguna revisión', function () {
    configurarGoogleCalendarPjud();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 18:15:00'));

    $this->artisan('pjud:status-monitoreo cierre')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events')
        && $request['summary'] === '⚠️ Monitoreo PJUD finalizado con observaciones'
        && str_contains($request['description'], 'Revisiones ejecutadas: 0')
        && str_contains($request['description'], 'No se ejecutó ninguna revisión hoy'));
});

test('el cierre cuenta las revisiones y consultas del día', function () {
    Queue::fake();
    configurarGoogleCalendarPjud();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:00'));
    $tribunal = tribunalPjud(6053101, '1º JUZGADO CIVIL DE SANTIAGO');
    Causa::factory()->create(['estado_causa_id' => estadoCausaId('VIGENTE'), 'juzgado_id' => Juzgado::factory()->create(['tribunal_pjud_codigo' => $tribunal->codigo])->id, 'numero_causa' => 'C-1-2026']);

    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();
    $this->artisan('pjud:revisar-estado-diario')->assertSuccessful();
    RegistroMonitoreoPjud::sumar('consultas_ok', 2);

    $this->travelTo(CarbonImmutable::parse('2026-11-06 18:15:00'));
    $this->artisan('pjud:status-monitoreo cierre')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/events')
        && $request['summary'] === '🏁 Monitoreo PJUD finalizado'
        && str_contains($request['description'], 'Revisiones ejecutadas: 2')
        && str_contains($request['description'], 'Consultas a tribunales correctas: 2')
        && str_contains($request['description'], 'Consultas con error: 0'));
});

test('el status de inicio y cierre queda programado según el horario', function () {
    config()->set('pjud.horario.hora_inicio', 8);
    config()->set('pjud.horario.hora_fin', 18);
    config()->set('pjud.notificacion.status_minutos_cierre', 15);

    expect(StatusMonitoreoPjud::horaInicio())->toBe('08:00')
        ->and(StatusMonitoreoPjud::horaCierre())->toBe('18:15');
});
