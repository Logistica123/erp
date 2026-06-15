<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.47.1 Bug #1 — Las reglas FIJO tienen cuenta asignada (no había FKs
 * huérfanas), pero su regex no matcheaba los conceptos REALES del extracto
 * ICBC abril 2026:
 *   - "COM RECH DDIR"  (el regex pedía "COMIS")
 *   - "PAGO AFIP ..."  (el regex pedía "PAGO IMP.AFIP")
 *   - "PAGO SAN CRISTO" (truncado; el regex pedía "SAN CRISTOBAL")
 *
 * Acá se corrige el patron_concepto de las reglas afectadas (idempotente).
 */
return new class extends Migration
{
    private function fixes(): array
    {
        return [
            'ICBC-COMIS-RECH-DDIR' => 'COM(IS)?\s+RECH\s+DDIR',
            'ICBC-PAGO-AFIP-GEN'   => 'PAGO\s+(IMP\.?\s*)?AFIP',
            'ICBC-PAGO-AFIP-F931'  => 'PAGO\s+(IMP\.?\s*)?AFIP\s+F\.?\s*931',
            'ICBC-PAGO-SEGURO'     => 'LA\s+SEGUNDA|MAPFRE|SAN\s+CRISTO(BAL)?|FEDERACION\s+PATRONAL|ALLIANZ|MERIDIONAL',
        ];
    }

    public function up(): void
    {
        foreach ($this->fixes() as $codigo => $patron) {
            DB::table('erp_conciliacion_reglas')->where('codigo', $codigo)
                ->update(['patron_concepto' => $patron, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Revertir el F931 y AFIP-GEN a su forma anterior (best-effort).
        DB::table('erp_conciliacion_reglas')->where('codigo', 'ICBC-COMIS-RECH-DDIR')->update(['patron_concepto' => 'COMIS\s+RECH\s+DDIR']);
        DB::table('erp_conciliacion_reglas')->where('codigo', 'ICBC-PAGO-AFIP-GEN')->update(['patron_concepto' => 'PAGO\s+IMP\.AFIP']);
        DB::table('erp_conciliacion_reglas')->where('codigo', 'ICBC-PAGO-AFIP-F931')->update(['patron_concepto' => 'PAGO\s+IMP\.AFIP\s+F\.?\s*931']);
        DB::table('erp_conciliacion_reglas')->where('codigo', 'ICBC-PAGO-SEGURO')->update(['patron_concepto' => 'LA\s+SEGUNDA|MAPFRE|SAN\s+CRISTOBAL|FEDERACION\s+PATRONAL|ALLIANZ|MERIDIONAL']);
    }
};
