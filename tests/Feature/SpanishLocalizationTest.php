<?php

test('las etiquetas predeterminadas del panel se traducen al español', function () {
    app()->setLocale('es');

    expect(__('Settings'))->toBe('Configuración')
        ->and(__('Profile'))->toBe('Perfil')
        ->and(__('Security'))->toBe('Seguridad')
        ->and(__('Appearance'))->toBe('Apariencia')
        ->and(__('Log out'))->toBe('Cerrar sesión');
});
