<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.48 — Tipos AFIP "Otros comprobantes RG 3419" (38, 39, 40, 41).
 *
 * Visto en prod (compras abril 2026): hay facturas con tipo 39 emitidas por
 * proveedores antiguos que rebotan el import porque el catálogo no los tenía.
 * Los códigos 38-41 son del Régimen General RG 3419 (recibos, certificados,
 * etc, que no están entre las facturas A/B/C estándar).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tipos = [
            // [id, codigo_interno, letra, nombre, clase, signo, discrimina_iva, es_fce]
            [38, 'OCB',  'B', 'Otros Comprobantes B (RG 3419)', 'FACTURA', 1, 0, 0],
            [39, 'OCA',  'A', 'Otros Comprobantes A (RG 3419)', 'FACTURA', 1, 1, 0],
            [40, 'OCC',  'C', 'Otros Comprobantes C (RG 3419)', 'FACTURA', 1, 0, 0],
            [41, 'OCM',  'M', 'Otros Comprobantes M (RG 3419)', 'FACTURA', 1, 1, 0],
        ];

        foreach ($tipos as [$id, $cod, $letra, $nombre, $clase, $signo, $discIva, $esFce]) {
            DB::statement(
                'INSERT IGNORE INTO erp_tipos_comprobante
                    (id, codigo_interno, letra, nombre, clase, signo, discrimina_iva, es_fce, activo)
                 VALUES (?,?,?,?,?,?,?,?,1)',
                [$id, $cod, $letra, $nombre, $clase, $signo, $discIva, $esFce],
            );
        }
    }

    public function down(): void
    {
        DB::table('erp_tipos_comprobante')->whereIn('id', [38, 39, 40, 41])->delete();
    }
};
