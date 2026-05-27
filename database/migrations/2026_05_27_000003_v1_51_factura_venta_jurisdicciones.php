<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.51 — Distribución de base imponible IIBB por jurisdicción a nivel
 * factura de venta.
 *
 * Hasta ahora la jurisdicción IIBB de una venta vivía suelta en
 * `erp_facturas_venta.jurisdiccion_codigo` (un solo valor, no editable desde
 * la UI) o por línea en `erp_factura_venta_items.jurisdiccion_iibb`. Pero las
 * facturas importadas del Libro IVA Ventas NO tienen ítems, así que no había
 * forma de:
 *   1) asignarles una jurisdicción distinta, ni
 *   2) repartir su base imponible entre varias jurisdicciones (caso típico:
 *      una mega-factura por servicios afectados a provincias distintas).
 *
 * Esta tabla guarda el reparto a nivel factura: una fila por jurisdicción con
 * su base imponible (neto gravado). La suma debe dar el neto gravado de la
 * factura (lo valida el endpoint). El IibbAtribucionService la lee con
 * prioridad sobre los ítems y sobre jurisdiccion_codigo.
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getSchemaBuilder()->hasTable('erp_factura_venta_jurisdicciones')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE erp_factura_venta_jurisdicciones (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                factura_venta_id    BIGINT UNSIGNED NOT NULL,
                jurisdiccion_codigo CHAR(3) NOT NULL,
                base_imponible      DECIMAL(18,2) NOT NULL,
                created_at          DATETIME NULL,
                updated_at          DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_fvj (factura_venta_id, jurisdiccion_codigo),
                KEY idx_fvj_factura (factura_venta_id),
                KEY idx_fvj_jur (jurisdiccion_codigo),
                CONSTRAINT fk_fvj_factura FOREIGN KEY (factura_venta_id)
                    REFERENCES erp_facturas_venta(id) ON DELETE CASCADE,
                CONSTRAINT fk_fvj_jur FOREIGN KEY (jurisdiccion_codigo)
                    REFERENCES erp_iibb_jurisdicciones(codigo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS erp_factura_venta_jurisdicciones');
    }
};
