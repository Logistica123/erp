<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza del duplicado de erp_secuencias_recibo causado por el desfasaje
 * PV '0001' (PV_DEFAULT viejo) vs '00001' (padeado por migración anterior).
 *
 * Antes de este parche:
 *   - erp_secuencias_recibo tenía 2 filas: '00001' (ultimo=21) y '0001' (ultimo=18).
 *   - El emit del service buscaba por '0001' (PV_DEFAULT viejo de 4 dígitos),
 *     no encontraba '00001', insertaba '0001' nuevo, asignaba numero 18.
 *   - El recibo id=7 quedó con numero=18 cuando debió ser 22 (max histórico
 *     local 21 + 1). El PDF impreso mostró 22 (draft local) pero la BD 18.
 *
 * Este parche:
 *   1. Recupera el max global entre ambas filas y los recibos persistidos.
 *   2. Borra la fila '0001' duplicada.
 *   3. Setea la fila '00001' al max correcto.
 *   4. Re-numera el recibo id=7 a 22 (coincide con el PDF que el usuario
 *      ya imprimió) y padea su punto_venta a '00001'.
 *
 * Idempotente: solo aplica si encuentra las inconsistencias específicas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Detectar si está la fila duplicada '0001'.
        $duplicada = DB::table('erp_secuencias_recibo')->where('punto_venta', '0001')->first();
        if (! $duplicada) {
            return; // ya limpio, nada que hacer.
        }

        // 2) Max global entre ambas filas.
        $maxSecuencia = DB::table('erp_secuencias_recibo')
            ->whereIn('punto_venta', ['0001', '00001'])
            ->max('ultimo_numero');
        $maxRecibos = DB::table('erp_recibos')
            ->whereIn('punto_venta', ['0001', '00001'])
            ->whereNotNull('numero')
            ->max(DB::raw('CAST(numero AS UNSIGNED)'));
        $max = max((int) $maxSecuencia, (int) $maxRecibos);

        // 3) Borrar la fila duplicada.
        DB::table('erp_secuencias_recibo')->where('punto_venta', '0001')->delete();

        // 4) Asegurar que existe la fila '00001' con el max correcto.
        $existe = DB::table('erp_secuencias_recibo')->where('punto_venta', '00001')->exists();
        if ($existe) {
            DB::table('erp_secuencias_recibo')
                ->where('punto_venta', '00001')
                ->update([
                    'ultimo_numero' => $max,
                    'observaciones' => DB::raw("CONCAT(IFNULL(observaciones,''), ' / 2026-06-05 unificado tras fix PV_DEFAULT 5 dígitos')"),
                ]);
        } else {
            DB::table('erp_secuencias_recibo')->insert([
                'punto_venta' => '00001',
                'ultimo_numero' => $max,
                'ultimo_emitido_por' => 'ERP',
                'ultimo_emitido_at' => now(),
                'observaciones' => '2026-06-05 unificado tras fix PV_DEFAULT 5 dígitos',
            ]);
        }

        // 5) Re-numerar el recibo id=7 si está con numero=18 + pv='0001'
        //    (el caso específico documentado: PDF se imprimió con 22 pero la
        //    BD persistió con 18 por el bug). Lo llevamos a 22 para coincidir
        //    con el comprobante físico que el usuario ya emitió.
        $r7 = DB::table('erp_recibos')->where('id', 7)->first(['punto_venta', 'numero', 'numero_correlativo']);
        if ($r7 && $r7->punto_venta === '0001' && $r7->numero === '00000018') {
            DB::table('erp_recibos')->where('id', 7)->update([
                'punto_venta' => '00001',
                'numero' => '00000022',
                'numero_correlativo' => '00001-00000022',
            ]);
            // El max ya quedó >= 22 si renumeramos a 22.
            DB::table('erp_secuencias_recibo')
                ->where('punto_venta', '00001')
                ->where('ultimo_numero', '<', 22)
                ->update(['ultimo_numero' => 22]);
        }
    }

    public function down(): void {}
};
