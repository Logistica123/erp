<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración complementaria al primer ALTER de PV a 5 dígitos:
 *  - Amplía erp_recibos.punto_venta CHAR(4) → CHAR(5).
 *  - Normaliza los valores existentes en ambas tablas a 5 dígitos
 *    (0001 → 00001) para que el frontend (que padea a 5) matchee
 *    los registros históricos.
 *
 * No hay FKs referenciando estos campos, así que no rompe nada.
 * Idempotente: solo modifica si la columna sigue siendo CHAR(4)
 * o si quedan valores de 4 dígitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Ampliar columna de erp_recibos.
        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'erp_recibos'
               AND COLUMN_NAME = 'punto_venta'"
        );
        if ($col && stripos((string) $col->COLUMN_TYPE, 'char(4)') !== false) {
            DB::statement("ALTER TABLE erp_recibos MODIFY punto_venta CHAR(5) NULL");
        }

        // 2) Padear valores históricos a 5 dígitos.
        DB::statement("
            UPDATE erp_secuencias_recibo
               SET punto_venta = LPAD(punto_venta, 5, '0')
             WHERE CHAR_LENGTH(punto_venta) < 5
        ");
        DB::statement("
            UPDATE erp_recibos
               SET punto_venta = LPAD(punto_venta, 5, '0')
             WHERE punto_venta IS NOT NULL
               AND CHAR_LENGTH(punto_venta) < 5
        ");
    }

    public function down(): void {}
};
