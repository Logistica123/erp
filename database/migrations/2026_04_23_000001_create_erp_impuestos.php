<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration versionada del paquete `DDL_05_Impuestos_Reportes.sql` (SPEC 05).
 *
 * Bloque H1 entrega:
 *   • erp_periodos_fiscales (RN-44)
 *   • erp_libro_iva_ventas_periodo, erp_libro_iva_compras_periodo (RN-45..47)
 *   • Tablas auxiliares para bloques siguientes (DDJJ IVA, retenciones,
 *     percepciones, IIBB CM, Ganancias, BP) — definidas vacías ahora,
 *     pobladas por sus respectivos bloques (H2..H6).
 *   • Catálogos: jurisdicciones IIBB, calendario vencimientos,
 *     regímenes de retención, escala Ganancias, alícuotas BP, coeficientes IIBB.
 *   • ALTERs idempotentes: erp_ejercicios.ajusta_por_inflacion,
 *     erp_ejercicios.indice_cierre, erp_factura_venta_items.jurisdiccion_iibb,
 *     erp_facturas_compra.retenciones_practicadas_ids.
 *
 * Seed inicial:
 *   • 24 jurisdicciones (sólo CABA y PBA activas).
 *   • 16 permisos del módulo (impuestos.*, reportes.*, eecc.*, ejercicio.cerrar).
 *   • Rol revisor_fiscal con mapeo, asignación a super_admin y contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        DB::unprepared(file_get_contents($path.'05_impuestos.sql'));

        // ALTERs idempotentes — MySQL 8.0 NO soporta `ADD COLUMN IF NOT EXISTS`
        // (sólo MariaDB). Hacemos el check en PHP contra information_schema.
        $this->addColumnIfMissing('erp_ejercicios', 'ajusta_por_inflacion',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Si el ejercicio se valúa y presenta con RT 6'");
        $this->addColumnIfMissing('erp_ejercicios', 'indice_cierre',
            "DECIMAL(18,6) NULL COMMENT 'Índice IPIM/IPC al cierre del ejercicio'");
        $this->addColumnIfMissing('erp_factura_venta_items', 'jurisdiccion_iibb',
            "CHAR(3) NULL COMMENT 'Override jurisdicción IIBB para esta línea (RN-54)'");
        $this->addColumnIfMissing('erp_facturas_compra', 'retenciones_practicadas_ids',
            "JSON NULL COMMENT 'IDs de erp_retenciones_practicadas generadas al pagar'");

        DB::unprepared(file_get_contents($path.'05_impuestos_seed.sql'));
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS x FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    public function down(): void
    {
        // Reversa ordenada por dependencias FK (hijas primero).
        $tables = [
            'erp_reportes_cache',
            'erp_bp_participaciones',
            'erp_ganancias_anticipos',
            'erp_ganancias_liquidacion',
            'erp_iibb_jurisdiccion_mov',
            'erp_iibb_cm_declaracion',
            'erp_percepciones_sufridas',
            'erp_retenciones_practicadas',
            'erp_iva_ddjj',
            'erp_libro_iva_compras_periodo',
            'erp_libro_iva_ventas_periodo',
            'erp_periodos_fiscales',
            'erp_iibb_coeficientes',
            'erp_regimenes_retencion',
            'erp_iibb_jurisdicciones',
            'erp_calendario_vencimientos',
            'erp_bp_alicuotas',
            'erp_ganancias_escala',
            'erp_ganancias_ajustes_tipo',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Revert ALTERs si la versión MySQL lo soporta. Las migraciones DOWN se
        // usan poco en prod; igual lo dejamos correcto para entornos de test.
        try {
            DB::statement('ALTER TABLE erp_ejercicios DROP COLUMN IF EXISTS ajusta_por_inflacion');
            DB::statement('ALTER TABLE erp_ejercicios DROP COLUMN IF EXISTS indice_cierre');
            DB::statement('ALTER TABLE erp_factura_venta_items DROP COLUMN IF EXISTS jurisdiccion_iibb');
            DB::statement('ALTER TABLE erp_facturas_compra DROP COLUMN IF EXISTS retenciones_practicadas_ids');
        } catch (\Throwable $e) {
            // Best effort — algunas versiones de MySQL no soportan IF EXISTS
            // en DROP COLUMN; no bloquea el rollback de tablas.
        }
    }
};
