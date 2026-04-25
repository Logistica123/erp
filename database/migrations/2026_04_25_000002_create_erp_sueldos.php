<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 08 bloque 8A — Sueldos y Nómina Ligera (DDL_08).
 *
 * Crea 16 tablas + 3 vistas + 3 triggers del módulo, 7 cuentas contables
 * nuevas (mapeo opción C — solo lo que falta en el plan vigente), 18
 * permisos sueldos.* y 2 roles (rrhh, contador_interno).
 *
 * Mapeo de cuentas (códigos del SPEC → códigos resueltos en plan vigente):
 *   1.1.5.01 Préstamos    → 1.1.5.10 (NUEVA)
 *   1.1.5.02 Adelantos    → 1.1.5.02 (Anticipos al Personal, ya existe)
 *   1.1.5.03 Combustible  → 1.1.5.11 (NUEVA)
 *   1.1.5.04 Pólizas      → 1.1.5.12 (NUEVA)
 *   1.1.5.05 Sanciones    → 1.1.5.13 (NUEVA)
 *   2.1.5.01 Sueldos pagar → 2.1.5.10 (NUEVA)
 *   2.1.5.02 Honorarios pagar → 2.1.5.05 (Honorarios a Pagar, ya existe)
 *   2.1.5.03 Cargas Soc pagar → 2.1.5.11 (NUEVA)
 *   5.2.1.01 Sueldos formal → 5.2.1.01 (Sueldos Administración, ya existe)
 *   5.2.1.02 Sueldos efect → 5.2.1.10 (NUEVA)
 *   5.2.1.03 Honorarios MT → 5.2.1.07 (Honorarios Profesionales, ya existe)
 *   5.2.1.05 SAC          → 5.2.1.03 (Aguinaldo (SAC), ya existe)
 *
 * Adaptaciones del DDL_08 al schema vigente:
 *   - FKs a erp_usuarios → users (Laravel default).
 *   - FK a erp_cajas_movimientos comentada (la tabla no existe en V1).
 *   - Triggers en archivo aparte (PDO no soporta DELIMITER del CLI mysql).
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        // 1. Tablas + vistas (sin triggers).
        DB::unprepared(file_get_contents($path.'08_sueldos_tables.sql'));

        // 2. Triggers — formato `//` separator.
        $triggers = file_get_contents($path.'08_sueldos_triggers.sql');
        foreach (explode('//', $triggers) as $stmt) {
            $lines = array_filter(
                explode("\n", $stmt),
                fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), '--')
            );
            $stmt = trim(implode("\n", $lines));
            if ($stmt === '') {
                continue;
            }
            DB::unprepared($stmt);
        }

        // 3. Cuentas nuevas (7) en códigos libres del rango.
        DB::unprepared(file_get_contents($path.'08_sueldos_cuentas.sql'));

        // 4. Seed: convenios + categorías + conceptos (con cuentas mapeadas) +
        //    permisos + roles + asignaciones.
        DB::unprepared(file_get_contents($path.'08_sueldos_seed.sql'));
    }

    public function down(): void
    {
        // 1. Permisos + asignaciones.
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (SELECT id FROM erp_permisos WHERE modulo='sueldos')");
        DB::statement("DELETE FROM erp_permisos WHERE modulo='sueldos'");
        DB::statement("DELETE FROM erp_roles WHERE codigo IN ('rrhh','contador_interno')");

        // 2. Cuentas nuevas (best effort — si tienen movimientos, fallará).
        foreach (['1.1.5.10','1.1.5.11','1.1.5.12','1.1.5.13','2.1.5.10','2.1.5.11','5.2.1.10'] as $cod) {
            DB::statement("DELETE FROM erp_cuentas_contables WHERE codigo = ?", [$cod]);
        }

        // 3. Triggers.
        foreach ([
            'tr_emp_liquidacion_cerrada_inmutable',
            'tr_emp_basicos_sin_overlap',
            'tr_emp_composicion_suma_100_upd',
            'tr_emp_composicion_suma_100_ins',
        ] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}");
        }

        // 4. Vistas.
        foreach (['v_erp_emp_costo_laboral', 'v_erp_emp_recibos_formales', 'v_erp_emp_saldos_cc'] as $v) {
            DB::statement("DROP VIEW IF EXISTS {$v}");
        }

        // 5. Tablas (orden inverso por FKs).
        $tables = [
            'erp_emp_export_liber',
            'erp_emp_pagos',
            'erp_emp_liquidaciones_items',
            'erp_emp_liquidaciones',
            'erp_emp_prestamos',
            'erp_emp_cc_movimientos',
            'erp_emp_cc',
            'erp_emp_ausencias',
            'erp_emp_novedades',
            'erp_emp_conceptos',
            'erp_emp_comisiones_esquema',
            'erp_emp_composicion_sueldo',
            'erp_emp_basicos_historial',
            'erp_emp_empleados',
            'erp_emp_categorias',
            'erp_emp_convenios',
        ];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
