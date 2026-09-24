<?php

namespace App\Enums;

enum EstadoImportacion: string
{
    case Pendiente = 'PENDIENTE';
    case Analizada = 'ANALIZADA';
    case Importando = 'IMPORTANDO';
    case Completada = 'COMPLETADA';
    case CompletadaConErrores = 'COMPLETADA_CON_ERRORES';
    case Fallida = 'FALLIDA';
}
