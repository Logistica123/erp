<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.19 — encoding auto-detectado en import del Libro IVA Compras.
 *
 * ALTER erp_libros_iva_compras_import ADD encoding_detectado VARCHAR(20) NULL
 *   (UTF-8, ISO-8859-1, Windows-1252, ASCII).
 *
 * Útil para diagnóstico cuando un import falla — saber con qué encoding se
 * leyó el archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            ['erp_libros_iva_compras_import', 'encoding_detectado']
        );
        if (! $exists) {
            DB::statement("ALTER TABLE erp_libros_iva_compras_import
                ADD COLUMN encoding_detectado VARCHAR(20) NULL
                COMMENT 'v1.19 — Encoding del archivo subido (UTF-8, ISO-8859-1, Windows-1252, ASCII) detectado al parsear.'
                AFTER archivo_hash");
        }
    }

    public function down(): void
    {
        try { DB::statement('ALTER TABLE erp_libros_iva_compras_import DROP COLUMN encoding_detectado'); } catch (\Throwable) {}
    }
};
