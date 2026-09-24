# Recordatorios: ejecución programada

SLAD procesa los recordatorios pendientes cada minuto mediante el comando `recordatorios:procesar`. La aplicación usa la zona horaria `America/Santiago`.

En producción debe configurarse una única tarea cron para el usuario que ejecuta PHP:

```cron
* * * * * cd /ruta/a/slad && php artisan schedule:run >> /dev/null 2>&1
```

El programador evita ejecuciones simultáneas. Las notificaciones se guardan exclusivamente dentro de SLAD, en la campana de notificaciones; no se envían a calendarios ni servicios externos.
