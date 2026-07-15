<?php

namespace App\Erp\Support;

/**
 * Workstream Sueldos Bloque 4 — G-04: helper único de auditoría del
 * módulo (el gap analysis encontró CERO llamadas al audit log en
 * sueldos; el spec §5.4 exige todas las acciones logueadas).
 */
class AuditoriaSueldos
{
    public static function log(string $accion, string $descripcion): void
    {
        try {
            app(AuditLogger::class)->logEvento(
                accion: $accion,
                modulo: 'sueldos',
                descripcion: $descripcion,
                empresaId: 1,
            );
        } catch (\Throwable) {
            // La auditoría nunca debe tumbar la operación (insert-only,
            // best-effort). El fallo queda en el log de Laravel.
            logger()->warning('auditoria sueldos falló', ['accion' => $accion]);
        }
    }
}
