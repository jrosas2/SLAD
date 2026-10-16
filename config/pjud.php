<?php

/*
|--------------------------------------------------------------------------
| Monitoreo del Estado Diario del Poder Judicial (PJUD)
|--------------------------------------------------------------------------
|
| SLAD revisa, cada hora dentro del horario configurado, el Estado Diario de
| los tribunales donde existen causas registradas. Cuando una causa aparece
| en el listado (match por número de ingreso + nombre del tribunal), se crea
| un evento "🚩 Actividad P. Judicial" en el calendario Google institucional.
|
| Ver docs/pjud-estado-diario.md para la guía completa de integración.
|
*/

return [

    // Interruptor general. Si está en false, el comando no consulta nada.
    'enabled' => (bool) env('PJUD_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Causas que se monitorean
    |--------------------------------------------------------------------------
    |
    | Sólo se consultan las causas cuyo "Estado de causa" está en esta lista
    | (catálogo estados_causa). Por defecto sólo VIGENTE: las CERRADAS no se
    | consultan, y un tribunal cuyas causas están todas cerradas no se revisa.
    | Varios estados separados por coma, ej. PJUD_ESTADOS_CAUSA=VIGENTE,SUSPENDIDA
    |
    */
    'estados_causa_consultables' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PJUD_ESTADOS_CAUSA', 'VIGENTE')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Coincidencia parcial del número de ingreso
    |--------------------------------------------------------------------------
    |
    | En SLAD el número no siempre se guardó completo. El match reconoce:
    |   exacta     "Ordinaria-5329-2025" = "Ordinaria-5329-2025"
    |   compatible "O-5329-2025" o "5329-2025"  (falta o se abrevió la letra)
    |   parcial    "5329" o "O-5329"            (falta el año)
    | Siempre dentro del mismo tribunal. Las parciales se avisan con una nota
    | para verificar. Con false, sólo se avisan exactas y compatibles.
    |
    */
    'coincidencia_parcial' => (bool) env('PJUD_COINCIDENCIA_PARCIAL', true),

    /*
    |--------------------------------------------------------------------------
    | Horario de consultas
    |--------------------------------------------------------------------------
    |
    | Se consulta una vez por hora entre hora_inicio y hora_fin (ambas
    | inclusive, hora de Chile según config/app.php). Fuera de ese rango el
    | comando termina sin consultar.
    |
    */
    'horario' => [
        'hora_inicio' => (int) env('PJUD_HORA_INICIO', 8),
        'hora_fin' => (int) env('PJUD_HORA_FIN', 18),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fuente del Estado Diario
    |--------------------------------------------------------------------------
    |
    | Es el mismo servicio que usa el botón "Estados Diarios" de cada tribunal
    | en www.pjud.cl/tribunales/... Todas las páginas de tribunal llaman al
    | MISMO endpoint; lo que cambia es el código de tribunal enviado:
    |
    |   POST https://www.pjud.cl/ajax/Courts/getDailyStatements
    |   cod_tribunal=6053101 & tipo_juzgado=6 & date=15-10-2026
    |
    | Responde HTML con una tabla: N° | Número de Ingreso | Partes | Providencias.
    | Se dejó configurable por si el PJUD cambia un nombre de parámetro.
    |
    | Use `php artisan pjud:probar-estado-diario {codigo_tribunal}` para
    | validar la configuración antes de activar el monitoreo.
    |
    */
    'estado_diario' => [
        // Página que se visita primero para obtener las cookies del sitio.
        'url_sesion' => env('PJUD_ESTADO_DIARIO_URL_SESION', 'https://www.pjud.cl/tribunales/corte-suprema'),

        // Endpoint que devuelve el listado del Estado Diario (HTML con una tabla).
        'url' => env('PJUD_ESTADO_DIARIO_URL', 'https://www.pjud.cl/ajax/Courts/getDailyStatements'),

        // Detalle de una causa del listado (Tipo, Estado, Materia, Procedimiento).
        // Sólo se consulta para las causas de SLAD detectadas. Vacío = no pedir detalle.
        'url_detalle' => env('PJUD_ESTADO_DIARIO_URL_DETALLE', 'https://www.pjud.cl/ajax/Courts/getDailyStatementByCause'),

        'metodo' => strtoupper((string) env('PJUD_ESTADO_DIARIO_METODO', 'POST')),

        // Nombre de cada campo en la petición del PJUD.
        'campos' => [
            'tribunal' => env('PJUD_CAMPO_TRIBUNAL', 'cod_tribunal'),
            'tipo_juzgado' => env('PJUD_CAMPO_TIPO_JUZGADO', 'tipo_juzgado'),
            'fecha' => env('PJUD_CAMPO_FECHA', 'date'),
        ],

        // Campos adicionales fijos que la página exija (ej. ['tipoBusqueda' => '1']).
        'campos_fijos' => [],

        'formato_fecha' => env('PJUD_FORMATO_FECHA', 'd-m-Y'),

        // Además de hoy, se revisan los N días anteriores. El PJUD publica el
        // Estado Diario con desfase (a media mañana suele estar el del día
        // anterior); los movimientos repetidos no se vuelven a notificar.
        'dias_atras' => (int) env('PJUD_DIAS_ATRAS', 1),

        'timeout' => (int) env('PJUD_TIMEOUT', 30),

        'user_agent' => env('PJUD_USER_AGENT', 'SLAD/1.0 (monitoreo estado diario)'),

        // Segundos entre la consulta de un tribunal y el siguiente (cortesía con el PJUD).
        'segundos_entre_tribunales' => (int) env('PJUD_SEGUNDOS_ENTRE_TRIBUNALES', 5),

        /*
        | Encabezados de la tabla del Estado Diario que el parser reconoce.
        | Se comparan sin tildes, sin mayúsculas y por coincidencia parcial.
        */
        'columnas' => [
            'numero_ingreso' => ['rol', 'rit', 'n ingreso', 'nro ingreso', 'numero ingreso', 'ingreso', 'causa'],
            'tribunal' => ['tribunal', 'juzgado'],
            'caratulado' => ['partes', 'caratulado', 'caratula'],
            'tramite' => ['providencias', 'tramite', 'descripcion', 'resolucion', 'actuacion'],
            'fecha' => ['fecha'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Equivalencias de nombres (vinculación automática de juzgados)
    |--------------------------------------------------------------------------
    |
    | Reemplazos aplicados sobre nombres ya normalizados (MAYÚSCULAS, sin
    | tildes ni "º") para que el catálogo de SLAD calce con el del PJUD.
    |
    */
    'equivalencias_nombre' => [
        '/\bJUZGADO DE LETRAS EN LO CIVIL\b/' => 'JUZGADO CIVIL',
        '/\bJUZGADO DEL TRABAJO\b/' => 'JUZGADO DE LETRAS DEL TRABAJO',
        '/\bTRIBUNAL JUICIO\b/' => 'TRIBUNAL DE JUICIO',
        '/\bJDOS\b/' => 'JUZGADOS',
        '/\bCOYHAIQUE\b/' => 'COIHAIQUE',
        '/\bPUERTO AYSEN\b/' => 'AISEN',
        '/\bPUERTO CISNES\b/' => 'CISNES',
        '/\bPUERTO NATALES\b/' => 'NATALES',
        '/\bSAN VICENTE DE TAGUA TAGUA\b/' => 'SAN VICENTE',
        '/\bDE LA CALERA\b/' => 'DE CALERA',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notificación en Google Calendar
    |--------------------------------------------------------------------------
    |
    | Usa la misma conexión institucional de Google Calendar que los
    | recordatorios (Administración → Google Calendar).
    |
    */
    'notificacion' => [
        'asunto' => env('PJUD_NOTIFICACION_ASUNTO', '🚩 Actividad P. Judicial'),

        // Minutos de anticipación de la alerta (correo + emergente).
        'alerta_minutos_antes' => (int) env('PJUD_ALERTA_MINUTOS', 1),

        // El evento se agenda N minutos después de detectar el movimiento,
        // para que la alerta de 1 minuto alcance a dispararse.
        'minutos_hasta_evento' => (int) env('PJUD_MINUTOS_HASTA_EVENTO', 2),

        'duracion_minutos' => (int) env('PJUD_DURACION_EVENTO_MINUTOS', 15),

        // Invitar al responsable de la causa (su correo corporativo en SLAD).
        'invitar_responsable' => (bool) env('PJUD_INVITAR_RESPONSABLE', true),

        // Correos corporativos adicionales separados por coma.
        'correos_adicionales' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PJUD_CORREOS_NOTIFICACION', '')),
        ))),
    ],
];
