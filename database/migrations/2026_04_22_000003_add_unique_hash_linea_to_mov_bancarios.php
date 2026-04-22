<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC 02 §7.1 y RN-12: el dedupe entre extractos del mismo período usa
 * (cuenta_bancaria_id, hash_linea) como natural key. El DDL_03 original
 * dejó el índice como comentario pero no lo creó. Este delta lo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: chequea antes de agregar.
        $exists = DB::selectOne("
            SELECT COUNT(*) AS n FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'erp_movimientos_bancarios'
              AND INDEX_NAME = 'uk_mov_bancarios_hash_linea'
        ");

        if ((int) $exists->n === 0) {
            DB::statement('
                ALTER TABLE erp_movimientos_bancarios
                ADD UNIQUE KEY uk_mov_bancarios_hash_linea (cuenta_bancaria_id, hash_linea)
            ');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE erp_movimientos_bancarios DROP KEY uk_mov_bancarios_hash_linea');
    }
};
