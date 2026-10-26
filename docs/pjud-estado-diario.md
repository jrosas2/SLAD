# Monitoreo del Estado Diario PJUD → Google Calendar

Este módulo revisa cada hora, de **08:00 a 18:00**, el Estado Diario del Poder Judicial. Solo consulta las causas **vigentes** (Estado de causa = `VIGENTE`) y solo los tribunales donde SLAD tiene al menos una causa vigente. Las causas cerradas no se consultan. Cuando una causa de SLAD aparece en el listado, crea un evento en el calendario Google institucional:

- **Asunto:** `🚩 Actividad P. Judicial`
- **Alerta:** 1 minuto antes, por correo y como notificación emergente.
- **Detalles:** número de causa, tribunal, nombre de la causa, partes, cantidad de providencias del día, detalle PJUD (tipo, estado, materia), fecha del estado diario y enlace a la causa en SLAD.
- **Invitados:** el correo corporativo del responsable de la causa, más los correos que configure.

---

## 1. Cómo funciona

```text
Scheduler (cada hora, 08:00–18:00)
   └─ pjud:revisar-estado-diario
        └─ toma solo los tribunales PJUD que tienen causas en SLAD
             └─ crea un job ConsultarEstadoDiarioTribunal por tribunal (escalonados)
                  ├─ EstadoDiarioPjudClient  → descarga el listado del día
                  ├─ EstadoDiarioHtmlParser  → lee la tabla (Rol, Tribunal, Trámite…)
                  └─ DetectorMovimientosPjud → hace el match número de ingreso + tribunal
                       └─ movimiento nuevo → crea el job NotificarMovimientoPjudGoogleCalendar
                                             └─ crea el evento "🚩 Actividad P. Judicial"
```

### Match: los dos datos clave

Una fila del Estado Diario corresponde a una causa de SLAD solo si coinciden los dos datos:

1. **Número de ingreso:** se compara el número del listado con `causas.numero_causa`, aunque en SLAD esté guardado incompleto. Siempre se compara dentro del mismo tribunal:

   | En SLAD | PJUD informa | Resultado |
   | --- | --- | --- |
   | `Ordinaria-5329-2025` | `Ordinaria-5329-2025` | **Exacta** |
   | `O-5329-2025` o `5329-2025` | `Ordinaria-5329-2025` | **Compatible**: falta la letra o está abreviada |
   | `5329` u `O-5329` | `Ordinaria-5329-2025` | **Parcial**: falta el año. El evento incluye "⚠️ Coincidencia parcial… verifique" |
   | `V-5329-2025` o `5329-2024` | `Ordinaria-5329-2025` | No calza: otra letra u otro año |

   Antes de comparar se ignoran mayúsculas, ceros a la izquierda y paréntesis (`(AMP) 11-2026`). Las coincidencias parciales se pueden desactivar con `PJUD_COINCIDENCIA_PARCIAL=false`. El tipo de coincidencia queda guardado en `pjud_movimientos.tipo_coincidencia`.
2. **Nombre del tribunal:** el tribunal de la fila tiene que ser el tribunal PJUD vinculado al juzgado de la causa. Al comparar se ignoran mayúsculas, tildes y `º`. Si el listado no trae una columna de tribunal, se usa el tribunal que se consultó.

### Sin avisos duplicados

Cada movimiento se guarda en `pjud_movimientos` con una **huella** única, calculada a partir de la causa, el tribunal, la fecha, el número y el trámite. Aunque el mismo movimiento siga apareciendo en las consultas de las horas siguientes, se notifica **una sola vez**.

---

## 2. Archivos del módulo

| Archivo | Para qué sirve |
| --- | --- |
| `config/pjud.php` | Toda la configuración: horario, URL del PJUD, nombres de los campos, columnas, asunto, alerta e invitados. |
| `database/seeders/data/tribunales_pjud.csv` | Los 472 códigos de tribunal del Excel que nos compartieron. Corregí dos nombres que venían truncados ("…DE VALPA" y "…DE CONCE"). |
| `database/seeders/TribunalPjudSeeder.php` | Carga ese CSV en la tabla `tribunales_pjud`. Se puede ejecutar varias veces sin problema. |
| `database/migrations/2026_10_16_1200*` | Crean `tribunales_pjud`, agregan `juzgados.tribunal_pjud_codigo` y crean `pjud_movimientos`. |
| `app/Models/TribunalPjud.php`, `app/Models/PjudMovimiento.php` | Modelos nuevos. |
| `app/Support/Pjud/NormalizadorPjud.php` | Normaliza el número de ingreso y el nombre del tribunal. |
| `app/Services/Pjud/FuenteEstadoDiario.php` | Interfaz con el origen de los datos. Permite cambiar el origen sin tocar el resto. |
| `app/Services/Pjud/EstadoDiarioPjudClient.php` | Consulta la web del PJUD según lo que diga `config/pjud.php`. |
| `app/Services/Pjud/EstadoDiarioHtmlParser.php` | Lee la tabla del Estado Diario por **nombre** de columna, no por posición. |
| `app/Services/Pjud/DetectorMovimientosPjud.php` | Hace el match y registra los movimientos nuevos. |
| `app/Services/Pjud/VinculadorJuzgadosPjud.php` | Asigna automáticamente el código PJUD a cada juzgado de SLAD. |
| `app/Jobs/ConsultarEstadoDiarioTribunal.php` | Job en cola que consulta un tribunal. |
| `app/Jobs/NotificarMovimientoPjudGoogleCalendar.php` | Job en cola que crea el evento en Google Calendar. |
| `app/Console/Commands/RevisarEstadoDiarioPjud.php` | `pjud:revisar-estado-diario`, el comando que corre cada hora. |
| `app/Console/Commands/VincularJuzgadosPjud.php` | `pjud:vincular-juzgados` |
| `app/Console/Commands/ProbarEstadoDiarioPjud.php` | `pjud:probar-estado-diario`, para diagnóstico. |
| `tests/Feature/PjudEstadoDiarioTest.php`, `tests/Unit/*Pjud*`, `tests/Unit/EstadoDiarioHtmlParserTest.php` | Tests en Pest. |

**Archivos existentes que se modificaron** (cambios pequeños y aditivos):

- `app/Services/GoogleCalendarService.php`: método nuevo `crearEventoActividadJudicial()`. Reutiliza la conexión institucional y los tokens que ya existen; los recordatorios no cambian.
- `app/Models/Juzgado.php`: campo `tribunal_pjud_codigo` y relación `tribunalPjud()`.
- `app/Models/Causa.php`: relación `pjudMovimientos()`.
- `app/Providers/AppServiceProvider.php`: registra la fuente del Estado Diario y el parser.
- `routes/console.php`: programación horaria.
- `database/seeders/DatabaseSeeder.php`: agrega `TribunalPjudSeeder`.
- `resources/views/pages/catalogos/⚡juzgados.blade.php`: columna y campo **Código tribunal PJUD**.
- `.env.example`: variables `PJUD_*`.

---

## 3. Pasos para integrarlo

### Paso 1: incorporar el código

Aplique la rama o el parche al repositorio. El monitoreo queda **desactivado** por defecto (`PJUD_ENABLED=false`), así que el despliegue no cambia nada en producción hasta que usted lo active.

### Paso 2: migrar y vincular los juzgados

```bash
php artisan migrate
php artisan pjud:vincular-juzgados --simular -v   # revisa el resultado sin guardar
php artisan pjud:vincular-juzgados                # guarda los códigos
```

Con el catálogo inicial de SLAD se vinculan automáticamente **400 de 403 juzgados**. Los tres que quedan no figuran en el Excel del PJUD:

- 8º Juzgado del Crimen de San Miguel
- 5º Juzgado del Trabajo de Santiago
- 6º Juzgado del Trabajo de Santiago

Si producción tiene juzgados con otros nombres, el comando los lista. Puede asignarles el código a mano en **Catálogos → Juzgados → Editar → Código tribunal PJUD**. El comando nunca sobrescribe un código que ya esté asignado, salvo que se use `--sobrescribir`.

### Paso 3: la consulta al PJUD (ya viene configurada)

SLAD usa el mismo servicio que el botón **"Estados Diarios"** de cada página de tribunal en `www.pjud.cl/tribunales/…`. Aunque cada tribunal tenga su propia página, todas llaman al **mismo endpoint** y solo cambia el código del tribunal:

```text
POST https://www.pjud.cl/ajax/Courts/getDailyStatements
     cod_tribunal=6053101   tipo_juzgado=6   date=15-10-2026
```

La respuesta es una tabla con las columnas **N° · Número de Ingreso · Partes · Providencias**. No trae columna de tribunal, porque el listado ya corresponde al tribunal consultado. Para las causas de SLAD que aparecen en el listado, también se pide el detalle (Tipo, Estado, Materia, Procedimiento) a `ajax/Courts/getDailyStatementByCause`, y ese detalle se agrega al evento.

Estos valores ya vienen por defecto. Solo hay que tocarlos si el PJUD cambia algo:

```dotenv
PJUD_ESTADO_DIARIO_URL=https://www.pjud.cl/ajax/Courts/getDailyStatements
PJUD_ESTADO_DIARIO_URL_DETALLE=https://www.pjud.cl/ajax/Courts/getDailyStatementByCause
PJUD_CAMPO_TRIBUNAL=cod_tribunal
PJUD_CAMPO_TIPO_JUZGADO=tipo_juzgado
PJUD_CAMPO_FECHA=date
PJUD_FORMATO_FECHA=d-m-Y
PJUD_DIAS_ATRAS=1
```

**Desfase de publicación:** el Estado Diario de hoy normalmente no está disponible a media mañana. Por eso, en cada revisión se consultan **hoy y el día anterior** (`PJUD_DIAS_ATRAS=1`). Los movimientos que ya se notificaron no se vuelven a notificar.

Para probar con un tribunal real (no guarda ni notifica nada):

```bash
php artisan pjud:probar-estado-diario 6053101 --fecha=2026-10-15
```

Debería mostrar los números de ingreso, las partes y la cantidad de providencias.

### Paso 4: configurar la notificación

Se usa la **misma conexión institucional de Google Calendar** que ya tienen los recordatorios (**Administración → Google Calendar** y `GOOGLE_CALENDAR_ENABLED=true`).

```dotenv
PJUD_NOTIFICACION_ASUNTO="🚩 Actividad P. Judicial"
PJUD_ALERTA_MINUTOS=1
PJUD_MINUTOS_HASTA_EVENTO=2        # el evento se agenda 2 min después de la detección
PJUD_INVITAR_RESPONSABLE=true      # invita al correo corporativo del responsable
PJUD_CORREOS_NOTIFICACION=procuraduria@empresa.cl,jefatura@empresa.cl
```

Sobre la alerta de 1 minuto: Google aplica los recordatorios del evento (correo y emergente, 1 minuto antes) a la **cuenta del calendario institucional**. Los invitados reciben la **invitación por correo** de inmediato (`sendUpdates=all`) y ven el evento con el asunto 🚩 en su propio calendario.

### Paso 5: activar

```dotenv
PJUD_ENABLED=true
```

Haga una prueba manual dentro del horario, o use `--forzar` si está fuera de él:

```bash
php artisan pjud:revisar-estado-diario --sync --forzar
```

Para la operación normal tienen que estar corriendo el **scheduler** (`php artisan schedule:work` o el cron con `schedule:run`) y un **worker de cola** (`php artisan queue:work`). Ambos ya se usan para los recordatorios.

---

## 4. Horario

En `routes/console.php`:

```php
Schedule::command('pjud:revisar-estado-diario')
    ->hourly()
    ->between('08:00', '18:00')
    ->withoutOverlapping();
```

Corre a las 08:00, 09:00 y así hasta las 18:00, en hora de Chile (`America/Santiago`). Fuera de ese rango no se consulta. Además, el propio comando vuelve a revisar el horario, así que una ejecución accidental fuera de él no consulta al PJUD. Puede cambiar el rango con `PJUD_HORA_INICIO` y `PJUD_HORA_FIN`.

Entre un tribunal y el siguiente se esperan `PJUD_SEGUNDOS_ENTRE_TRIBUNALES` segundos (5 por defecto), para no saturar al PJUD.

---

## 5. Tests

```bash
php artisan test --filter=Pjud
php artisan test --filter=EstadoDiarioHtmlParser
```

Lo que cubren:

- No se consulta fuera del horario.
- Solo se consultan los tribunales que tienen causas.
- El match exige número de ingreso y tribunal.
- Un movimiento no se notifica dos veces.
- El evento tiene el asunto 🚩, la alerta de 1 minuto, el número de causa y los invitados correctos.
- La vinculación automática asigna los códigos esperados.

---

## 6. Diagnóstico rápido

| Síntoma | Qué revisar |
| --- | --- |
| "No hay tribunales con causas vinculadas" | Ejecute `pjud:vincular-juzgados` y confirme que las causas tienen juzgado y `numero_causa`. |
| "No se reconocieron filas" | Puede ser que el PJUD aún no publique esa fecha (responde "No se ha encontrado información de estados diarios"). Pruebe con el día hábil anterior. Si sigue fallando, use `--guardar-html` y revise la respuesta. |
| Se detectan movimientos, pero no aparece el evento | Revise `GOOGLE_CALENDAR_ENABLED`, la conexión en Administración y que el worker de cola esté corriendo. Mire `storage/logs/laravel.log`. |
| Una causa vigente no se revisa | Confirme que su **Estado de causa** sea `VIGENTE`. Las causas sin estado o cerradas se omiten (se puede cambiar con `PJUD_ESTADOS_CAUSA`). |
| Una causa no calza | Compare su `numero_causa` con el del listado (ej. `C-1234-2025`) y el código PJUD de su juzgado. |
