<?php

use App\Enums\Permiso;
use App\Http\Controllers\CausaDocumentoController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\GoogleCalendarOAuthController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('cambiar-contrasena-inicial', 'pages::auth.first-change-password')
    ->middleware(['auth', EnsureUserIsActive::class])
    ->name('password.first-change');

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
    Route::get('admin/google-calendar', [GoogleCalendarOAuthController::class, 'show'])->name('admin.google-calendar.show');
    Route::get('admin/google-calendar/connect', [GoogleCalendarOAuthController::class, 'redirect'])->name('admin.google-calendar.connect');
    Route::get('admin/google-calendar/callback', [GoogleCalendarOAuthController::class, 'callback'])->name('admin.google-calendar.callback');
    Route::delete('admin/google-calendar', [GoogleCalendarOAuthController::class, 'destroy'])->name('admin.google-calendar.destroy');

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

Route::middleware(['auth'])->group(function () {
    Route::get('/google/redirect', [GoogleCalendarController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/google/callback', [GoogleCalendarController::class, 'handleGoogleCallback'])->name('google.callback');
});

require __DIR__.'/settings.php';

// Rutas Google Calendar
Route::middleware(['auth'])->group(function () {
    Route::get('/google/redirect', [GoogleCalendarController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/google/callback', [GoogleCalendarController::class, 'handleGoogleCallback'])->name('google.callback');
});
