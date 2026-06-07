<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.36 cierre — Aplica el UNIQUE KEY (cuit, tipo) en `erp_auxiliares` que
 * quedó pendiente del addendum del 2026-05-26. Antes:
 *
 *  1) Mergea cualquier dup remanente (mueve FKs al canónico, borra el dummy).
 *  2) Normaliza placeholders CUIT='11111111111' a NULL (no son dups reales —
 *     son distribuidores reales sin CUIT real cargado). MySQL UNIQUE acepta
 *     múltiples NULLs así que conviven sin romper el constraint.
 *  3) ALTER ADD UNIQUE KEY uk_aux_cuit_tipo (cuit, tipo).
 *
 * Idempotente: si no hay dups y el índice ya existe, no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -- Paso 1: mergear dups remanentes -------------------------------
        // Mantiene el id más bajo (canónico = más antiguo / más referenciado).
        $grupos = DB::table('erp_auxiliares')
            ->whereNotNull('cuit')->where('cuit', '!=', '')->where('cuit', '!=', '11111111111')
            ->select('cuit', 'tipo', DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'), DB::raw('COUNT(*) c'))
            ->groupBy('cuit', 'tipo')->havingRaw('COUNT(*) > 1')->get();

        foreach ($grupos as $g) {
            $ids = array_map('intval', explode(',', $g->ids));
            $canonicoId = $ids[0];
            $dupIds = array_slice($ids, 1);
            foreach ($dupIds as $dupId) {
                $this->mergearAuxiliar($dupId, $canonicoId);
            }
        }

        // -- Paso 2: normalizar placeholders y string vacío a NULL ----------
        // MySQL UNIQUE permite múltiples NULLs (multivaluado en estándar SQL),
        // así que cualquier valor "no-CUIT real" debe ser NULL para coexistir.
        $placeholders = ['11111111111', '00000000000', '99999999999'];
        DB::table('erp_auxiliares')->whereIn('cuit', $placeholders)->update(['cuit' => null]);
        DB::table('erp_auxiliares')->where('cuit', '')->update(['cuit' => null]);

        // -- Paso 3: agregar UNIQUE KEY (cuit, tipo) -----------------------
        $existeIdx = collect(DB::select("SHOW INDEX FROM erp_auxiliares"))
            ->contains(fn ($r) => $r->Key_name === 'uk_aux_cuit_tipo');
        if (! $existeIdx) {
            Schema::table('erp_auxiliares', function (Blueprint $t) {
                $t->unique(['cuit', 'tipo'], 'uk_aux_cuit_tipo');
            });
        }

        // -- Paso 4: validar invariante post-migración ---------------------
        $dupsRestantes = DB::scalar("
            SELECT COUNT(*) FROM (
                SELECT cuit FROM erp_auxiliares
                WHERE cuit IS NOT NULL AND cuit != ''
                GROUP BY cuit, tipo HAVING COUNT(*) > 1
            ) t
        ");
        if ($dupsRestantes > 0) {
            throw new \RuntimeException("v1.36 cierre falló: {$dupsRestantes} dups restantes tras el merge.");
        }
    }

    public function down(): void
    {
        Schema::table('erp_auxiliares', function (Blueprint $t) {
            $t->dropUnique('uk_aux_cuit_tipo');
        });
        // Merge no es reversible; placeholders quedan NULL.
    }

    /**
     * Mueve todas las FKs apuntando a $dupId hacia $canonicoId y borra el dup.
     * Las tablas/columnas vienen de INFORMATION_SCHEMA (verificado contra prod).
     */
    private function mergearAuxiliar(int $dupId, int $canonicoId): void
    {
        $fks = [
            ['erp_af_bienes', 'proveedor_auxiliar_id'],
            ['erp_centros_costo', 'auxiliar_id'],
            ['erp_cliente_saldos_cc', 'auxiliar_id'],
            ['erp_cobros', 'auxiliar_id'],
            ['erp_conciliacion_reglas', 'auxiliar_id'],
            ['erp_facturas_compra', 'auxiliar_id'],
            ['erp_facturas_compra', 'cliente_auxiliar_id'],
            ['erp_facturas_venta', 'auxiliar_id'],
            ['erp_movimientos_asiento', 'auxiliar_id'],
            ['erp_ordenes_pago', 'auxiliar_id'],
            ['erp_proveedor_saldos_cc', 'auxiliar_id'],
            ['erp_recibos', 'cliente_auxiliar_id'],
            ['erp_retenciones_practicadas', 'proveedor_id'],
            ['erp_saldos_cuenta', 'auxiliar_id'],
        ];
        foreach ($fks as [$tabla, $col]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $col)) continue;
            DB::table($tabla)->where($col, $dupId)->update([$col => $canonicoId]);
        }
        DB::table('erp_auxiliares')->where('id', $dupId)->delete();
    }
};
