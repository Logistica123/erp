<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AFIP transitó el PV de comprobantes de 4 a 5 dígitos. Ampliamos la columna
 * `punto_venta` de `erp_secuencias_recibo` de CHAR(4) a CHAR(5) para que
 * los valores históricos (0001) y los nuevos (00001) convivan sin truncar.
 *
 * Idempotente: solo altera si la columna sigue siendo CHAR(4).
 */
return new class extends Migration
{
    public function up(): void
    {
        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'erp_secuencias_recibo'
               AND COLUMN_NAME = 'punto_venta'"
        );
        if ($col && stripos((string) $col->COLUMN_TYPE, 'char(4)') !== false) {
            DB::statement("ALTER TABLE erp_secuencias_recibo MODIFY punto_venta CHAR(5) NOT NULL");
        }
    }

    public function down(): void
    {
        // No volvemos a 4: si hubo registros con 5 dígitos los truncaria.
    }
};
