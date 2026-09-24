<?php

use App\Models\Actuacion;
use App\Models\Causa;
use App\Models\DocumentoCausa;
use App\Models\MovimientoFinanciero;
use App\Models\User;
use App\Services\DashboardStatsService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function accessFilters(): array
{
    return [
        'year' => null,
        'materiaId' => null,
        'responsableId' => null,
        'estadoCausaId' => null,
        'estadoProcesalId' => null,
        'ciudadId' => null,
        'juzgadoId' => null,
    ];
}

test('el administrador y quien tiene permiso global ven todas las causas', function () {
    $administrador = User::factory()->administrador()->create();
    $abogado = User::factory()->abogado()->create();
    $propia = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $sinResponsable = Causa::factory()->create(['responsable_id' => null]);

    expect(Causa::query()->visibleFor($administrador)->pluck('id')->all())
        ->toContain($propia->id, $sinResponsable->id);

    $usuarioGlobal = User::factory()->consulta()->create();
    $usuarioGlobal->givePermissionTo(Permission::findOrCreate('causas.ver-todas', 'web'));

    expect(Causa::query()->visibleFor($usuarioGlobal)->pluck('id')->all())
        ->toContain($propia->id, $sinResponsable->id);
});

test('el abogado solo lista, busca y filtra sus propias causas', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    Causa::factory()->create(['nombre' => 'CAUSA PROPIA', 'numero_causa' => 'MIA-100', 'responsable_id' => $abogado->id]);
    Causa::factory()->create(['nombre' => 'CAUSA AJENA', 'numero_causa' => 'AJENA-200', 'responsable_id' => $otroAbogado->id]);
    Causa::factory()->create(['nombre' => 'CAUSA SIN RESPONSABLE', 'numero_causa' => 'LIBRE-300', 'responsable_id' => null]);

    Livewire::actingAs($abogado)
        ->test('pages::causas.index')
        ->assertSee('CAUSA PROPIA')
        ->assertDontSee('CAUSA AJENA')
        ->assertDontSee('CAUSA SIN RESPONSABLE')
        ->set('search', 'AJENA-200')
        ->assertDontSee('CAUSA AJENA')
        ->set('search', '')
        ->set('responsableFilter', (string) $otroAbogado->id)
        ->assertDontSee('CAUSA AJENA');
});

test('el abogado recibe 403 al abrir o editar una causa ajena y puede editar la propia', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    $propia = Causa::factory()->create(['responsable_id' => $abogado->id]);
    $ajena = Causa::factory()->create(['responsable_id' => $otroAbogado->id]);

    $this->actingAs($abogado)
        ->get(route('causas.show', $propia))
        ->assertOk();
    $this->get(route('causas.edit', $propia))->assertOk();
    $this->get(route('causas.show', $ajena))->assertForbidden();
    $this->get(route('causas.edit', $ajena))->assertForbidden();
});

test('el abogado no puede reasignar el responsable de una causa propia', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);

    Livewire::actingAs($abogado)
        ->test('pages::causas.form', ['causa' => $causa])
        ->set('responsableId', (string) $otroAbogado->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($causa->refresh()->responsable_id)->toBe($abogado->id);
});

test('el abogado no accede a actuaciones, movimientos ni documentos de causas ajenas', function () {
    Storage::fake('local');
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    $causaAjena = Causa::factory()->create(['responsable_id' => $otroAbogado->id]);
    $actuacion = Actuacion::factory()->for($causaAjena)->create();
    $movimiento = MovimientoFinanciero::factory()->for($causaAjena)->create();
    $documento = DocumentoCausa::factory()->for($causaAjena)->create();
    Storage::disk('local')->put($documento->ruta, "%PDF-1.4\n%%EOF");

    Livewire::actingAs($abogado)
        ->test('causas.actuaciones', ['causa' => $causaAjena])
        ->assertForbidden();
    Livewire::actingAs($abogado)
        ->test('causas.movimientos-financieros', ['causa' => $causaAjena])
        ->assertForbidden();
    Livewire::actingAs($abogado)
        ->test('causas.documentos', ['causa' => $causaAjena])
        ->assertForbidden();
    $this->actingAs($abogado)
        ->get(route('causas.documentos.download', [$causaAjena, $documento]))
        ->assertForbidden();

    expect($abogado->can('view', $actuacion))->toBeFalse()
        ->and($abogado->can('view', $movimiento))->toBeFalse();
});

test('el dashboard del abogado calcula solo sus causas', function () {
    $abogado = User::factory()->abogado()->create();
    $otroAbogado = User::factory()->abogado()->create();
    Causa::factory()->count(2)->create(['responsable_id' => $abogado->id]);
    Causa::factory()->count(3)->create(['responsable_id' => $otroAbogado->id]);
    Causa::factory()->create(['responsable_id' => null]);

    $dashboard = app(DashboardStatsService::class)->dashboard(accessFilters(), $abogado);

    expect($dashboard['resumen']['totalCausas'])->toBe(2);
});
