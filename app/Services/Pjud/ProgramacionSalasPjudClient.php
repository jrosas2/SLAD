<?php

namespace App\Services\Pjud;

use App\Models\TribunalPjud;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Consulta la "Programación de Salas" de UNA Corte en la web del PJUD: es el
 * mismo servicio que usa ese botón en www.pjud.cl/tribunales/corte-....
 *
 *   1. Tipos de tabla (ordinaria, agregada, radicada…)  → JSON
 *   2. Por cada tipo, las salas y días con tabla         → HTML Fecha | Sala | Relator
 *   3. Por cada sala y día, las causas en tabla          → HTML N° | Causa | Ingreso
 *
 * Sólo existe para las Cortes: tipo de juzgado 1 (Suprema, condición COSUP) y
 * 2 (Apelaciones, condición CAPEL). URLs y pausas vienen de config/pjud.php.
 */
class ProgramacionSalasPjudClient implements FuenteProgramacionSalas
{
    private ?CookieJar $cookies = null;

    public function __construct(private readonly ProgramacionSalasHtmlParser $parser) {}

    /**
     * Condición que el PJUD espera para cada tipo de Corte, o null si el
     * tribunal no publica programación de salas (primera instancia).
     */
    public static function condicion(TribunalPjud $tribunal): ?string
    {
        return match ((int) $tribunal->tipo_juzgado) {
            1 => 'COSUP',
            2 => 'CAPEL',
            default => null,
        };
    }

    public function obtener(TribunalPjud $corte, CarbonImmutable $desde): array
    {
        $condicion = self::condicion($corte) ?? throw new InvalidArgumentException("El tribunal {$corte->codigo} no es una Corte: no publica programación de salas.");
        $desde = $desde->startOfDay();
        $entradas = [];

        foreach ($this->tiposDeTabla($corte, $condicion) as $codigoTipo => $nombreTipo) {
            $this->pausa();

            $salas = $this->parser->salas($this->enviar('url_salas', [
                'codTypeTable' => $codigoTipo,
                'codTribunal' => $corte->codigo,
                'condicion' => $condicion,
            ]));

            foreach ($salas as $sala) {
                if ($sala['fecha']->lessThan($desde)) {
                    continue;
                }

                $this->pausa();

                $constitucion = $this->parser->causas($this->enviar('url_constitucion', [
                    'numSala' => $sala['sala_numero'],
                    'codCorte' => $corte->codigo,
                    'tipoTabla' => $codigoTipo,
                    'fecha' => $sala['fecha']->format('d/m/Y'),
                    'nomsala' => '',
                    'condicion' => $condicion,
                ]));

                foreach ($constitucion['causas'] as $causa) {
                    $entradas[] = new EntradaProgramacion(
                        tipoTablaCodigo: (string) $codigoTipo,
                        tipoTabla: $nombreTipo,
                        fecha: $sala['fecha'],
                        salaNumero: $sala['sala_numero'],
                        sala: $sala['sala'],
                        numeroIngreso: $causa['numero_ingreso'],
                        caratulado: $causa['caratulado'],
                        numeroOrden: $causa['orden'],
                        relator: $constitucion['relator'] ?? $sala['relator'],
                    );
                }
            }
        }

        return $entradas;
    }

    /**
     * Tipos de tabla de la Corte: código → nombre (sin la opción vacía "...").
     *
     * @return array<string, string>
     */
    public function tiposDeTabla(TribunalPjud $corte, string $condicion): array
    {
        $json = json_decode($this->enviar('url_tipos_tabla', [
            'codTribunal' => $corte->codigo,
            'tipoJuzgado' => $corte->tipo_juzgado,
            'condicion' => $condicion,
        ]), true);

        if (! is_array($json) || ($json['status'] ?? false) !== true) {
            throw new RuntimeException("El PJUD no devolvió los tipos de tabla de la Corte {$corte->codigo}.");
        }

        $tipos = [];
        foreach ((array) data_get($json, 'data.typesTable', []) as $tipo) {
            $codigo = trim((string) ($tipo['id'] ?? ''));
            $nombre = trim((string) preg_replace('/\s+/', ' ', (string) ($tipo['value'] ?? '')));

            if ($codigo === '' || $codigo === '0' || $nombre === '' || trim($nombre, '.') === '') {
                continue;
            }

            $tipos[$codigo] = $nombre;
        }

        return $tipos;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function enviar(string $claveUrl, array $datos): string
    {
        $url = config("pjud.programacion.{$claveUrl}");

        if (blank($url)) {
            throw new RuntimeException("Falta configurar la URL pjud.programacion.{$claveUrl} (PJUD_PROGRAMACION_URL_*).");
        }

        // Multipart, igual que el FormData que envía la página del PJUD.
        $respuesta = $this->cliente()->asMultipart()->post((string) $url, array_map(
            fn ($valor): string => (string) $valor,
            $datos,
        ));

        $respuesta->throw();

        return $respuesta->body();
    }

    private function cliente(): PendingRequest
    {
        $config = (array) config('pjud.estado_diario');

        $nuevo = fn (): PendingRequest => Http::withUserAgent((string) ($config['user_agent'] ?? 'SLAD'))
            ->withHeaders(['Accept' => 'text/html,application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->connectTimeout(10)
            ->retry([2000, 5000], throw: false);

        // Visita la página de las Cortes una sola vez para obtener las cookies del sitio.
        $urlSesion = config('pjud.programacion.url_sesion');
        if ($this->cookies === null && filled($urlSesion)) {
            $this->cookies = $nuevo()->get((string) $urlSesion)->cookies();
        }

        return $this->cookies === null ? $nuevo() : $nuevo()->withOptions(['cookies' => $this->cookies]);
    }

    private function pausa(): void
    {
        $segundos = max(0, (int) config('pjud.programacion.segundos_entre_consultas', 1));

        if ($segundos > 0) {
            sleep($segundos);
        }
    }
}
