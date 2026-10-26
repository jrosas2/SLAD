<?php

namespace App\Console\Commands;

use App\Models\TribunalPjud;
use App\Services\Pjud\EstadoDiarioHtmlParser;
use App\Services\Pjud\EstadoDiarioPjudClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('pjud:probar-estado-diario
    {tribunal : Código PJUD del tribunal (ej. 6053101)}
    {--fecha= : Fecha a consultar (AAAA-MM-DD). Por defecto, hoy}
    {--guardar-html : Guarda la respuesta cruda en storage/app/private/pjud para revisarla}')]
#[Description('Consulta el Estado Diario de un tribunal y muestra lo que SLAD entiende (no guarda ni notifica nada)')]
class ProbarEstadoDiarioPjud extends Command
{
    public function handle(EstadoDiarioPjudClient $cliente, EstadoDiarioHtmlParser $parser): int
    {
        $tribunal = TribunalPjud::query()->find((int) $this->argument('tribunal'));

        if ($tribunal === null) {
            $this->error('Código de tribunal no encontrado. ¿Ejecutó php artisan pjud:vincular-juzgados?');

            return self::FAILURE;
        }

        $fecha = filled($this->option('fecha')) ? CarbonImmutable::parse((string) $this->option('fecha')) : CarbonImmutable::today();

        $this->info("Consultando {$tribunal->nombre} (tipo {$tribunal->tipo_juzgado}) para el {$fecha->format('d-m-Y')}…");

        $html = $cliente->descargar($tribunal, $fecha);

        if ($this->option('guardar-html')) {
            $ruta = 'pjud/estado-diario-'.$tribunal->codigo.'-'.$fecha->format('Ymd').'-'.Str::random(6).'.html';
            Storage::disk('local')->put($ruta, $html);
            $this->line("Respuesta guardada en storage/app/private/{$ruta}");
        }

        $entradas = $parser->parse($html);

        if ($entradas === []) {
            $this->warn('No se reconocieron filas. Revise la URL/campos en .env o los encabezados en config/pjud.php (use --guardar-html).');

            return self::SUCCESS;
        }

        $this->table(
            ['N° ingreso', 'Tribunal', 'Partes', 'Providencias'],
            collect($entradas)->take(30)->map(fn ($entrada) => [
                $entrada->numeroIngreso,
                Str::limit((string) $entrada->tribunal, 40),
                Str::limit((string) $entrada->caratulado, 40),
                Str::limit((string) $entrada->tramite, 50),
            ])->all(),
        );

        $this->info(count($entradas).' fila(s) reconocida(s).');

        return self::SUCCESS;
    }
}
