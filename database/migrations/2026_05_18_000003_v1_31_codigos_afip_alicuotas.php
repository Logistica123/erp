<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.31 — Corrige códigos AFIP en erp_alicuotas_iva.
 *
 * AFIP rechazó el F.8001 con errores "El impuesto liquidado con alícuota
 * 0% no puede ser distinto de cero" y "Para la alícuota 10.5% el impuesto
 * liquidado debe ser igual al 10.5%". Diagnóstico:
 *
 * Los códigos AFIP en `erp_alicuotas_iva` estaban mapeados a tasas
 * INCORRECTAS. Verificado contra el archivo byte-perfect de LIBER del
 * 2026-03 (que sí estaba bien): el código `0004` corresponde a 10.5%
 * (no a 27%), `0006` corresponde a 27% (no a 2.5%).
 *
 * Mapeo AFIP correcto (RG 5616/2024 + verificado contra LIBER 2026-03):
 *   0003 → 0% / Exento
 *   0004 → 10.5%
 *   0005 → 21%
 *   0006 → 27%
 *   0008 → 5%
 *   0009 → 2.5%
 *
 * Antes de la migración, solo 4 filas en `erp_factura_compra_iva` usaban
 * la alícuota id=5 (21%, código correcto) — el cambio es seguro.
 */
return new class extends Migration
{
    private const FIXES = [
        // [tasa, codigo_afip_correcto, nombre]
        [0.0000, '0003', 'IVA 0% (Exento/No gravado)'],
        [0.1050, '0004', 'IVA 10.5%'],
        [0.2100, '0005', 'IVA 21%'],
        [0.2700, '0006', 'IVA 27%'],
        [0.0500, '0008', 'IVA 5%'],
        [0.0250, '0009', 'IVA 2.5%'],
    ];

    public function up(): void
    {
        foreach (self::FIXES as [$tasa, $codigo, $nombre]) {
            DB::table('erp_alicuotas_iva')
                ->where('tasa', $tasa)
                ->update(['codigo_afip' => $codigo]);
        }
    }

    public function down(): void
    {
        // Reversa al estado anterior (códigos intercambiados):
        $reverso = [
            [0.0000, '0009'], // 0% → 0009 (estaba mal asignado)
            [0.1050, '0003'], // 10.5% → 0003 (estaba mal asignado)
            [0.2100, '0005'], // 21% → 0005 (ya estaba bien)
            [0.2700, '0004'], // 27% → 0004 (estaba mal asignado)
            [0.0500, '0008'], // 5% → 0008 (ya estaba bien)
            [0.0250, '0006'], // 2.5% → 0006 (estaba mal asignado)
        ];
        foreach ($reverso as [$tasa, $codigo]) {
            DB::table('erp_alicuotas_iva')
                ->where('tasa', $tasa)
                ->update(['codigo_afip' => $codigo]);
        }
    }
};
