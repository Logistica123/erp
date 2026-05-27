<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.36 — Bypass de sesión en trg_fv_inmutable_bu para el merge de auxiliares.
 *
 * El trigger de inmutabilidad fiscal (RN-32) bloquea cambiar auxiliar_id en
 * facturas de venta con CAE. El merge de auxiliares duplicados (mismo cliente
 * real, solo se reapunta el maestro) necesita reasignar ese puntero. Se agrega
 * una exención SOLO para auxiliar_id cuando la sesión setea @erp_merge_auxiliares
 * — el resto de los guards fiscales (importes, CAE, número, PV, tipo) siguen
 * activos siempre.
 *
 * Idempotente: no hace nada si el trigger ya tiene el bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::selectOne(
            "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_fv_inmutable_bu'"
        );
        if ($row && str_contains((string) $row->ACTION_STATEMENT, '@erp_merge_auxiliares')) {
            return; // ya aplicado (ej: se hizo a mano en prod)
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_fv_inmutable_bu');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fv_inmutable_bu BEFORE UPDATE ON erp_facturas_venta FOR EACH ROW
            BEGIN
                IF OLD.cae IS NOT NULL AND OLD.estado IN ('EMITIDA','CONTROLADA','COBRO_PARCIAL','COBRADA') THEN
                    IF NEW.tipo_comprobante_id <> OLD.tipo_comprobante_id OR
                       NEW.punto_venta_id     <> OLD.punto_venta_id     OR
                       NEW.numero             <> OLD.numero             OR
                       NEW.cae                <> OLD.cae                OR
                       NEW.imp_neto_gravado   <> OLD.imp_neto_gravado   OR
                       NEW.imp_iva            <> OLD.imp_iva            OR
                       NEW.imp_total          <> OLD.imp_total          OR
                       (NEW.auxiliar_id <> OLD.auxiliar_id AND @erp_merge_auxiliares IS NULL) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Factura con CAE es inmutable (RN-32). Solo se permite cambio de estado.';
                    END IF;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        // Restaura la versión sin bypass.
        DB::unprepared('DROP TRIGGER IF EXISTS trg_fv_inmutable_bu');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_fv_inmutable_bu BEFORE UPDATE ON erp_facturas_venta FOR EACH ROW
            BEGIN
                IF OLD.cae IS NOT NULL AND OLD.estado IN ('EMITIDA','CONTROLADA','COBRO_PARCIAL','COBRADA') THEN
                    IF NEW.tipo_comprobante_id <> OLD.tipo_comprobante_id OR
                       NEW.punto_venta_id     <> OLD.punto_venta_id     OR
                       NEW.numero             <> OLD.numero             OR
                       NEW.cae                <> OLD.cae                OR
                       NEW.imp_neto_gravado   <> OLD.imp_neto_gravado   OR
                       NEW.imp_iva            <> OLD.imp_iva            OR
                       NEW.imp_total          <> OLD.imp_total          OR
                       NEW.auxiliar_id        <> OLD.auxiliar_id        THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Factura con CAE es inmutable (RN-32). Solo se permite cambio de estado.';
                    END IF;
                END IF;
            END
            SQL);
    }
};
