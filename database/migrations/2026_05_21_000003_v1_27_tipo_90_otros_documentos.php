<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipo de comprobante AFIP 90 — Otros Documentos.
 *
 * Visto en prod (libro IVA compras mayo 2026): facturas con tipo 90 que el
 * catálogo no tenía y rebotaban "tipo de comprobante 90 no está en catálogo".
 * Según RG 5616 / Tabla AFIP, el 90 es "Otros Documentos" (recibos diversos,
 * comprobantes de servicios públicos, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'INSERT IGNORE INTO erp_tipos_comprobante
                (id, codigo_interno, letra, nombre, clase, signo, discrimina_iva, es_fce, activo)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [90, 'OTRO_DOC', null, 'Otros Documentos (tasas, contribuciones, recibos varios)', 'OTRO', 1, 0, 0, 1],
        );
    }

    public function down(): void
    {
        DB::table('erp_tipos_comprobante')->where('id', 90)->delete();
    }
};
