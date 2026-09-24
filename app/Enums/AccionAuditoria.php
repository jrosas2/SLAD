<?php

namespace App\Enums;

enum AccionAuditoria: string
{
    case Creado = 'CREADO';
    case Modificado = 'MODIFICADO';
    case Eliminado = 'ELIMINADO';
    case DocumentoAdjuntado = 'DOCUMENTO_ADJUNTADO';
    case DocumentoEliminado = 'DOCUMENTO_ELIMINADO';
    case RecordatorioCreado = 'RECORDATORIO_CREADO';
    case RecordatorioModificado = 'RECORDATORIO_MODIFICADO';
    case RecordatorioReprogramado = 'RECORDATORIO_REPROGRAMADO';
    case RecordatorioCompletado = 'RECORDATORIO_COMPLETADO';
    case RecordatorioCancelado = 'RECORDATORIO_CANCELADO';
}
