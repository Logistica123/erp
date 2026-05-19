<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.38 — Tipos de comprobante AFIP que más se usan en práctica (RG 5616).
 *
 * Antes solo había 28 tipos básicos (FA/FB/FC/ND/NC/RA/RB/RC + M + FCE).
 * Sumamos los códigos AFIP frecuentes que aparecen en facturas reales:
 * Liquidación Única Comercial Impositiva, Tickets factura, Tique NC/ND,
 * Resumen de tarjeta, Otros comprobantes, etc.
 *
 * No tocamos los IDs existentes — solo INSERT IGNORE (preserva integridad
 * FK con facturas ya cargadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        // [id, codigo_interno, letra, nombre, clase, signo, discrimina_iva, es_fce]
        $tipos = [
            // Recibos de Venta al Contado (RG 100)
            [5,   'RVA',  'A',  'Nota de Venta al Contado A',                    'RECIBO',       1,  1, 0],
            [10,  'RVB',  'B',  'Nota de Venta al Contado B',                    'RECIBO',       1,  0, 0],

            // Liquidación Única Comercial Impositiva
            [27,  'LIQA', 'A',  'Liquidación Única Comercial Impositiva A',      'FACTURA',      1,  1, 0],
            [28,  'LIQB', 'B',  'Liquidación Única Comercial Impositiva B',      'FACTURA',      1,  0, 0],
            [29,  'LIQC', 'C',  'Liquidación Única Comercial Impositiva C',      'FACTURA',      1,  0, 0],

            // Cuenta de Venta y Líquido Producto (consignaciones agropecuarias)
            [60,  'CVLA', 'A',  'Cuenta de Venta y Líquido Producto A',          'FACTURA',      1,  1, 0],
            [61,  'CVLB', 'B',  'Cuenta de Venta y Líquido Producto B',          'FACTURA',      1,  0, 0],

            // Tickets fiscales
            [81,  'TFA',  'A',  'Tique Factura A',                                'FACTURA',      1,  1, 0],
            [82,  'TFB',  'B',  'Tique Factura B',                                'FACTURA',      1,  0, 0],
            [83,  'TIQ',  null, 'Tique',                                          'TICKET',       1,  0, 0],

            // Resumen de tarjeta + Otros
            [89,  'RTC',  null, 'Resumen de tarjeta de crédito',                  'OTRO',         1,  0, 0],
            [91,  'REM',  'R',  'Remito R',                                       'OTRO',         1,  0, 0],
            [99,  'OTRO', null, 'Otros Comprobantes',                             'OTRO',         1,  0, 0],

            // Tique factura RG 1415
            [111, 'TFA1', 'A',  'Tique Factura A (RG 1415)',                      'FACTURA',      1,  1, 0],
            [112, 'TFB1', 'B',  'Tique Factura B (RG 1415)',                      'FACTURA',      1,  0, 0],
            [113, 'TFC1', 'C',  'Tique Factura C (RG 1415)',                      'FACTURA',      1,  0, 0],
            [114, 'TNCA', 'A',  'Tique Nota de Crédito A',                        'NOTA_CREDITO', -1, 1, 0],
            [115, 'TNCB', 'B',  'Tique Nota de Crédito B',                        'NOTA_CREDITO', -1, 0, 0],
            [116, 'TNCC', 'C',  'Tique Nota de Crédito C',                        'NOTA_CREDITO', -1, 0, 0],
            [117, 'TNDA', 'A',  'Tique Nota de Débito A',                         'NOTA_DEBITO',  1,  1, 0],
            [118, 'TNDB', 'B',  'Tique Nota de Débito B',                         'NOTA_DEBITO',  1,  0, 0],
            [119, 'TNDC', 'C',  'Tique Nota de Débito C',                         'NOTA_DEBITO',  1,  0, 0],

            // Recibos FCE MiPyME (cancelación)
            [195, 'RFCEA','A',  'Recibo Factura de Crédito Electrónica MiPyME A', 'RECIBO',       1,  1, 1],
            [196, 'RFCEB','B',  'Recibo Factura de Crédito Electrónica MiPyME B', 'RECIBO',       1,  0, 1],
            [197, 'RFCEC','C',  'Recibo Factura de Crédito Electrónica MiPyME C', 'RECIBO',       1,  0, 1],
        ];

        foreach ($tipos as [$id, $cod, $letra, $nombre, $clase, $signo, $discIva, $esFce]) {
            // INSERT IGNORE: si el id o codigo_interno ya existe, no toca nada.
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
        DB::table('erp_tipos_comprobante')->whereIn('id', [
            5, 10, 27, 28, 29, 60, 61, 81, 82, 83, 89, 91, 99,
            111, 112, 113, 114, 115, 116, 117, 118, 119,
            195, 196, 197,
        ])->delete();
    }
};
