<?php

namespace App\Services\Pjud;

use App\Models\TribunalPjud;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Consulta el Estado Diario de UN tribunal en la web del PJUD.
 *
 * Es el mismo servicio que usa el botón "Estados Diarios" de las páginas
 * www.pjud.cl/tribunales/...: todas llaman al mismo endpoint y sólo cambia
 * el código del tribunal.
 *
 *   Listado: POST https://www.pjud.cl/ajax/Courts/getDailyStatements
 *            cod_tribunal, tipo_juzgado, date (dd-mm-aaaa)
 *   Detalle: POST https://www.pjud.cl/ajax/Courts/getDailyStatementByCause
 *            tipo_causa, rol, era, cod_tribunal, tipo_juzgado, cod_oracle, fecha
 *
 * URL, método y nombres de campos vienen de config/pjud.php (.env).
 */
class EstadoDiarioPjudClient implements FuenteEstadoDiario
{
    private ?CookieJar $cookies = null;

    public function __construct(private readonly EstadoDiarioHtmlParser $parser) {}

    public function obtener(TribunalPjud $tribunal, CarbonImmutable $fecha): array
    {
        return $this->parser->parse($this->descargar($tribunal, $fecha));
    }

    /**
     * Devuelve el HTML crudo del listado (útil para el comando de prueba).
     */
    public function descargar(TribunalPjud $tribunal, CarbonImmutable $fecha): string
    {
        $config = (array) config('pjud.estado_diario');
        $url = $config['url'] ?? null;

        if (blank($url)) {
            throw new RuntimeException('Falta configurar PJUD_ESTADO_DIARIO_URL en el archivo .env.');
        }

        $campos = (array) ($config['campos'] ?? []);
        $valores = [
            'tribunal' => $tribunal->codigo,
            'tipo_juzgado' => $tribunal->tipo_juzgado,
            'fecha' => $fecha->format((string) ($config['formato_fecha'] ?? 'd-m-Y')),
        ];

        $datos = (array) ($config['campos_fijos'] ?? []);
        foreach ($valores as $clave => $valor) {
            $campo = $campos[$clave] ?? null;

            // Un campo vacío en la configuración significa "no enviar este dato".
            if (filled($campo)) {
                $datos[(string) $campo] = $valor;
            }
        }

        return $this->enviar((string) $url, $datos);
    }

    /**
     * Detalle de la causa (Tipo, Estado, Materia, Procedimiento…) usando los
     * datos del enlace del listado. Si falla, devuelve null: el aviso se envía
     * igual, sólo que sin este detalle.
     */
    public function detalle(TribunalPjud $tribunal, CarbonImmutable $fecha, EntradaEstadoDiario $entrada): ?string
    {
        $url = config('pjud.estado_diario.url_detalle');
        $datos = $entrada->datos;

        if (blank($url) || blank($datos['rol'] ?? null) || blank($datos['era'] ?? null)) {
            return null;
        }

        try {
            $html = $this->enviar((string) $url, [
                'tipo_causa' => $datos['tipocausa'] ?? '',
                'rol' => $datos['rol'],
                'era' => $datos['era'],
                'cod_tribunal' => $datos['codtribunal'] ?? $tribunal->codigo,
                'tipo_juzgado' => $datos['tipojuzgado'] ?? $tribunal->tipo_juzgado,
                'nombre_tribunal' => $datos['nombretribunal'] ?? $tribunal->nombre,
                'cod_oracle' => $datos['codoracle'] ?? '',
                'fecha' => $datos['date'] ?? $fecha->format('d-m-Y'),
            ]);

            return $this->resumenDetalle($html);
        } catch (Throwable $exception) {
            Log::notice('No se pudo obtener el detalle PJUD de la causa.', [
                'tribunal' => $tribunal->codigo,
                'numero' => $entrada->numeroIngreso,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convierte la tabla del detalle en "Tipo: Principal · Estado: Tramitación · …".
     */
    private function resumenDetalle(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $xpath = new DOMXPath($dom);
        $encabezados = [];
        foreach ($xpath->query('//table//th') ?: [] as $th) {
            if ($th instanceof DOMElement) {
                $encabezados[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
            }
        }

        $partes = [];
        foreach ($xpath->query('//table//tbody/tr') ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            foreach ($xpath->query('./td', $tr) ?: [] as $indice => $td) {
                if (! $td instanceof DOMElement) {
                    continue;
                }

                $titulo = $encabezados[$indice] ?? '';
                $valor = trim((string) preg_replace('/\s+/u', ' ', $td->textContent));

                if ($titulo !== '' && ! in_array($titulo, ['N°', 'Nº', 'N'], true) && $valor !== '') {
                    $partes[] = "{$titulo}: {$valor}";
                }
            }

            if ($partes !== []) {
                break; // Primera fila con datos.
            }
        }

        return $partes === [] ? null : mb_substr(implode(' · ', $partes), 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function enviar(string $url, array $datos): string
    {
        $respuesta = config('pjud.estado_diario.metodo', 'POST') === 'GET'
            ? $this->cliente()->get($url, $datos)
            : $this->cliente()->asForm()->post($url, $datos);

        $respuesta->throw();

        return $respuesta->body();
    }

    private function cliente(): PendingRequest
    {
        $config = (array) config('pjud.estado_diario');

        $nuevo = fn (): PendingRequest => Http::withUserAgent((string) ($config['user_agent'] ?? 'SLAD'))
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'X-Requested-With' => 'XMLHttpRequest'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->connectTimeout(10)
            ->retry([2000, 5000], throw: false);

        // Visita la página del tribunal una sola vez para obtener las cookies del sitio.
        if ($this->cookies === null && filled($config['url_sesion'] ?? null)) {
            $this->cookies = $nuevo()->get((string) $config['url_sesion'])->cookies();
        }

        return $this->cookies === null ? $nuevo() : $nuevo()->withOptions(['cookies' => $this->cookies]);
    }
}
