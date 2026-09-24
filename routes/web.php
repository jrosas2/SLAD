<?php

use App\Enums\Permiso;
use App\Http\Controllers\CausaDocumentoController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth', EnsureUserIsActive::class, EnsurePasswordChanged::class, 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')
        ->middleware('can:'.Permiso::DashboardVer->value)
        ->name('dashboard');

    Route::livewire('admin/usuarios', 'pages::admin.users.index')
        ->middleware('can:'.Permiso::UsuariosVer->value)
        ->name('admin.users.index');

    Route::livewire('admin/roles', 'pages::admin.roles.index')
        ->middleware('can:'.Permiso::RolesVer->value)
        ->name('admin.roles.index');

    Route::livewire('admin/importaciones', 'pages::admin.importaciones.index')
        ->middleware('can:'.Permiso::ImportacionesVer->value)
        ->name('admin.importaciones.index');

    Route::livewire('admin/auditoria', 'pages::admin.auditoria.index')
        ->middleware('can:'.Permiso::AuditoriaVer->value)
        ->name('admin.auditoria.index');

    Route::prefix('causas')
        ->name('causas.')
        ->group(function () {
            Route::livewire('/', 'pages::causas.index')
                ->middleware('can:'.Permiso::CausasVer->value)
                ->name('index');
            Route::livewire('crear', 'pages::causas.form')
                ->middleware('can:'.Permiso::CausasCrear->value)
                ->name('create');
            Route::livewire('{causa}/editar', 'pages::causas.form')
                ->middleware('can:'.Permiso::CausasEditar->value)
                ->name('edit');
            Route::get('{causa}/documentos/{documento}/ver', [CausaDocumentoController::class, 'view'])
                ->middleware('can:'.Permiso::DocumentosVer->value)
                ->name('documentos.view');
            Route::get('{causa}/documentos/{documento}/descargar', [CausaDocumentoController::class, 'download'])
                ->middleware('can:'.Permiso::DocumentosVer->value)
                ->name('documentos.download');
            Route::livewire('{causa}', 'pages::causas.show')
                ->middleware('can:'.Permiso::CausasVer->value)
                ->name('show');
        });

    Route::prefix('catalogos')
        ->middleware('can:'.Permiso::CatalogosVer->value)
        ->name('catalogos.')
        ->group(function () {
            Route::livewire('submaterias', 'pages::catalogos.submaterias')->name('submaterias.index');
            Route::livewire('juzgados', 'pages::catalogos.juzgados')->name('juzgados.index');
            Route::livewire('{catalogo}', 'pages::catalogos.simple')
                ->whereIn('catalogo', ['materias', 'ciudades', 'direcciones', 'estados-procesales', 'estados-causa', 'acciones'])
                ->name('simple.index');
        });
});

require __DIR__.'/settings.php';
