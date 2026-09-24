<?php

use App\Enums\AccionAuditoria;
use App\Models\Auditoria;
use App\Models\Causa;
use App\Models\DocumentoCausa;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function pdfUpload(string $name = 'antecedente.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
}

test('el detalle de causa sigue disponible para quien puede ver causas pero no documentos', function () {
    $usuario = User::factory()->create();
    $usuario->syncRoles([]);
    $usuario->givePermissionTo([
        Permission::findOrCreate('causas.ver', 'web'),
        Permission::findOrCreate('actuaciones.ver', 'web'),
        Permission::findOrCreate('movimientos.ver', 'web'),
    ]);
    $causa = Causa::factory()->create(['responsable_id' => $usuario->id]);

    expect($usuario->can('causas.ver'))->toBeTrue();
    $response = $this->actingAs($usuario)
        ->get(route('causas.show', $causa));

    $response
        ->assertOk()
        ->assertDontSee('Documentos adjuntos');
});

test('un abogado puede adjuntar un PDF privado a una causa', function () {
    Storage::fake('local');
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);

    Livewire::actingAs($abogado)
        ->test('causas.documentos', ['causa' => $causa])
        ->set('archivo', pdfUpload())
        ->set('descripcion', 'Antecedente principal')
        ->call('guardar')
        ->assertHasNoErrors();

    $documento = DocumentoCausa::query()->sole();
    expect($documento->causa_id)->toBe($causa->id)
        ->and($documento->user_id)->toBe($abogado->id)
        ->and($documento->descripcion)->toBe('Antecedente principal')
        ->and($documento->mime_type)->toBe('application/pdf');
    Storage::disk('local')->assertExists($documento->ruta);
    expect(Auditoria::query()->where('accion', AccionAuditoria::DocumentoAdjuntado)->count())->toBe(1);
});

test('no permite adjuntar el mismo PDF dos veces a la misma causa', function () {
    Storage::fake('local');
    $abogado = User::factory()->abogado()->create();
    $causa = Causa::factory()->create(['responsable_id' => $abogado->id]);

    $component = Livewire::actingAs($abogado)
        ->test('causas.documentos', ['causa' => $causa])
        ->set('archivo', pdfUpload())
        ->call('guardar')
        ->set('archivo', pdfUpload())
        ->call('guardar')
        ->assertHasErrors(['archivo']);

    expect(DocumentoCausa::query()->count())->toBe(1);
});

test('los documentos solo se entregan a usuarios con permiso de lectura', function () {
    Storage::fake('local');
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    $documento = DocumentoCausa::factory()->for($causa)->create();
    Storage::disk('local')->put($documento->ruta, "%PDF-1.4\n%%EOF");

    $this->actingAs($administrador)
        ->get(route('causas.documentos.view', [$causa, $documento]))
        ->assertOk();

    $sinPermiso = User::factory()->create();
    $sinPermiso->syncRoles([]);
    $this->actingAs($sinPermiso)
        ->get(route('causas.documentos.download', [$causa, $documento]))
        ->assertForbidden();
});

test('un administrador puede eliminar un documento y queda auditado', function () {
    Storage::fake('local');
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    $documento = DocumentoCausa::factory()->for($causa)->create();
    Storage::disk('local')->put($documento->ruta, "%PDF-1.4\n%%EOF");

    Livewire::actingAs($administrador)
        ->test('causas.documentos', ['causa' => $causa])
        ->call('confirmarEliminacion', $documento->id)
        ->call('eliminar')
        ->assertHasNoErrors();

    expect(DocumentoCausa::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($documento->ruta);
    expect(Auditoria::query()->where('accion', AccionAuditoria::DocumentoEliminado)->count())->toBe(1);
});

test('el administrador puede visualizar documentos sin cargar la causa de forma diferida', function () {
    $administrador = User::factory()->administrador()->create();
    $causa = Causa::factory()->create();
    DocumentoCausa::factory()->for($causa)->create(['nombre_original' => 'ANTECEDENTE VISIBLE.pdf']);

    Livewire::actingAs($administrador)
        ->test('causas.documentos', ['causa' => $causa])
        ->assertSee('ANTECEDENTE VISIBLE.pdf');
});
