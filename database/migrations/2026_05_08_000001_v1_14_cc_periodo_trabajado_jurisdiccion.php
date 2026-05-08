<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 — Centro de Costos por Cliente + Período Trabajado + Jurisdicción.
 *
 * Cambios respecto al v1.13:
 *   1. ALTER erp_centros_costo: agrega `auxiliar_id` (FK a erp_auxiliares) para
 *      vincular 1:1 cada cliente (auxiliar tipo=Cliente) con su CC.
 *      NB: el addendum dice "cliente_id" referenciando clientes(id) de DistriApp,
 *      pero esa tabla está cross-DB (no-FK posible). Mantenemos el bridge pattern
 *      de v1.13: usamos erp_auxiliares como puente.
 *
 *   2. DROP `periodo_pagado_texto` de erp_facturas_compra: era typo del v1.13
 *      (concepto cubierto por fecha_imputacion a nivel archivo, no por factura).
 *
 *   3. ALTER erp_facturas_compra y erp_facturas_venta:
 *        - `periodo_trabajado_texto` (período de servicio cubierto, NUEVO)
 *        - `jurisdiccion_codigo`     (FK a erp_iibb_jurisdicciones, NUEVO)
 *        - `centro_costo_id`         (FK derivada del auxiliar del cliente, NUEVO)
 *
 *   4. Migración inicial: poblar CCs para los auxiliares tipo='Cliente' que
 *      todavía no tienen CC asociado.
 *
 * Reusa la tabla existente `erp_iibb_jurisdicciones` (creada en SPEC 05 H1)
 * en lugar de crear `erp_jurisdicciones_afip` — los códigos son los mismos
 * (901-924 SIFERE/AFIP).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----- 1. ALTER erp_centros_costo ------------------------------------
        $this->addCol('erp_centros_costo', 'auxiliar_id',
            "BIGINT UNSIGNED NULL COMMENT 'FK al auxiliar (1:1) si este CC es de tipo=CLIENTE.'");

        if (! $this->indexExists('erp_centros_costo', 'uk_cc_auxiliar')) {
            DB::statement('ALTER TABLE erp_centros_costo ADD UNIQUE KEY uk_cc_auxiliar (auxiliar_id)');
        }
        if (! $this->fkExists('erp_centros_costo', 'fk_cc_auxiliar')) {
            DB::statement('ALTER TABLE erp_centros_costo
                ADD CONSTRAINT fk_cc_auxiliar FOREIGN KEY (auxiliar_id)
                REFERENCES erp_auxiliares(id)');
        }

        // ----- 2. DROP periodo_pagado_texto de erp_facturas_compra ----------
        // Era typo del v1.13: el concepto correcto "Período asignado" ya está
        // cubierto por fecha_imputacion (a nivel archivo). El nuevo concepto
        // del v1.14 es "Período trabajado" (período de servicio).
        $this->dropCol('erp_facturas_compra', 'periodo_pagado_texto');

        // ----- 3. ALTER erp_facturas_compra ---------------------------------
        $this->addCol('erp_facturas_compra', 'periodo_trabajado_texto',
            "VARCHAR(20) NULL COMMENT 'Periodo de servicio que cubre la factura. YYYY-MM o YYYY-MM-Q1/Q2.'");
        // NB: collation explícita para que la FK matchee con
        // erp_iibb_jurisdicciones.codigo (creada con utf8mb4_unicode_ci en
        // SPEC 05 H1 mientras el resto de las tablas usa el default utf8mb4_0900_ai_ci).
        $this->addCol('erp_facturas_compra', 'jurisdiccion_codigo',
            "CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Código AFIP IIBB de jurisdicción (901-924).'");
        // Si la columna ya existía con la collation default (parcial de un intento previo),
        // forzamos a la collation correcta.
        DB::statement("ALTER TABLE erp_facturas_compra MODIFY jurisdiccion_codigo CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        $this->addCol('erp_facturas_compra', 'centro_costo_id',
            "BIGINT UNSIGNED NULL COMMENT 'CC derivado del cliente_auxiliar_id.'");

        if (! $this->indexExists('erp_facturas_compra', 'idx_periodo_trab')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_periodo_trab (periodo_trabajado_texto)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_juris')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_juris (jurisdiccion_codigo)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_cc_compra')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_cc_compra (centro_costo_id)');
        }
        if (! $this->fkExists('erp_facturas_compra', 'fk_fc_juris')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT fk_fc_juris FOREIGN KEY (jurisdiccion_codigo)
                REFERENCES erp_iibb_jurisdicciones(codigo)');
        }
        if (! $this->fkExists('erp_facturas_compra', 'fk_fc_cc')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT fk_fc_cc FOREIGN KEY (centro_costo_id)
                REFERENCES erp_centros_costo(id)');
        }

        // ----- 4. ALTER erp_facturas_venta ----------------------------------
        $this->addCol('erp_facturas_venta', 'periodo_trabajado_texto',
            "VARCHAR(20) NULL COMMENT 'Periodo de servicio que cubre la factura. YYYY-MM o YYYY-MM-Q1/Q2.'");
        $this->addCol('erp_facturas_venta', 'jurisdiccion_codigo',
            "CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT 'Código AFIP IIBB de jurisdicción (901-924).'");
        DB::statement("ALTER TABLE erp_facturas_venta MODIFY jurisdiccion_codigo CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        $this->addCol('erp_facturas_venta', 'centro_costo_id',
            "BIGINT UNSIGNED NULL COMMENT 'CC derivado del cliente.'");

        if (! $this->indexExists('erp_facturas_venta', 'idx_periodo_trab')) {
            DB::statement('ALTER TABLE erp_facturas_venta ADD INDEX idx_periodo_trab (periodo_trabajado_texto)');
        }
        if (! $this->indexExists('erp_facturas_venta', 'idx_juris')) {
            DB::statement('ALTER TABLE erp_facturas_venta ADD INDEX idx_juris (jurisdiccion_codigo)');
        }
        if (! $this->indexExists('erp_facturas_venta', 'idx_cc_venta')) {
            DB::statement('ALTER TABLE erp_facturas_venta ADD INDEX idx_cc_venta (centro_costo_id)');
        }
        if (! $this->fkExists('erp_facturas_venta', 'fk_fv_juris')) {
            DB::statement('ALTER TABLE erp_facturas_venta
                ADD CONSTRAINT fk_fv_juris FOREIGN KEY (jurisdiccion_codigo)
                REFERENCES erp_iibb_jurisdicciones(codigo)');
        }
        if (! $this->fkExists('erp_facturas_venta', 'fk_fv_cc')) {
            DB::statement('ALTER TABLE erp_facturas_venta
                ADD CONSTRAINT fk_fv_cc FOREIGN KEY (centro_costo_id)
                REFERENCES erp_centros_costo(id)');
        }

        // ----- 5. Migración inicial: poblar CCs para auxiliares cliente -----
        // Por cada empresa, por cada auxiliar tipo=Cliente sin CC asociado,
        // crear un CC con código CLI-XXXX y vincularlo.
        $auxiliaresSinCc = DB::table('erp_auxiliares as a')
            ->leftJoin('erp_centros_costo as cc', 'cc.auxiliar_id', '=', 'a.id')
            ->where('a.tipo', 'Cliente')
            ->whereNull('cc.id')
            ->select('a.id', 'a.empresa_id', 'a.nombre')
            ->get();

        foreach ($auxiliaresSinCc as $aux) {
            $codigo = 'CLI-'.str_pad((string) $aux->id, 4, '0', STR_PAD_LEFT);
            $existeCodigo = DB::table('erp_centros_costo')
                ->where('empresa_id', $aux->empresa_id)
                ->where('codigo', $codigo)
                ->exists();
            if ($existeCodigo) {
                continue;
            }
            DB::table('erp_centros_costo')->insert([
                'empresa_id' => $aux->empresa_id,
                'codigo' => $codigo,
                'nombre' => $aux->nombre,
                'tipo' => 'CLIENTE',
                'auxiliar_id' => $aux->id,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ----- 6. Cachear centro_costo_id en facturas existentes ------------
        // Para facturas de compra que tienen cliente_auxiliar_id, derivar y
        // cachear el centro_costo_id del CC del auxiliar.
        DB::statement("
            UPDATE erp_facturas_compra fc
              JOIN erp_centros_costo cc ON cc.auxiliar_id = fc.cliente_auxiliar_id
               SET fc.centro_costo_id = cc.id
             WHERE fc.cliente_auxiliar_id IS NOT NULL
               AND fc.centro_costo_id IS NULL
        ");
    }

    public function down(): void
    {
        // FKs primero
        foreach (['fk_fv_juris','fk_fv_cc'] as $fk) {
            try { DB::statement("ALTER TABLE erp_facturas_venta DROP FOREIGN KEY {$fk}"); } catch (\Throwable) {}
        }
        foreach (['fk_fc_juris','fk_fc_cc'] as $fk) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP FOREIGN KEY {$fk}"); } catch (\Throwable) {}
        }
        try { DB::statement('ALTER TABLE erp_centros_costo DROP FOREIGN KEY fk_cc_auxiliar'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_centros_costo DROP INDEX uk_cc_auxiliar'); } catch (\Throwable) {}

        foreach (['idx_periodo_trab','idx_juris','idx_cc_venta'] as $idx) {
            try { DB::statement("ALTER TABLE erp_facturas_venta DROP INDEX {$idx}"); } catch (\Throwable) {}
        }
        foreach (['idx_periodo_trab','idx_juris','idx_cc_compra'] as $idx) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP INDEX {$idx}"); } catch (\Throwable) {}
        }

        foreach (['periodo_trabajado_texto','jurisdiccion_codigo','centro_costo_id'] as $col) {
            try { DB::statement("ALTER TABLE erp_facturas_venta DROP COLUMN {$col}"); } catch (\Throwable) {}
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP COLUMN {$col}"); } catch (\Throwable) {}
        }
        try { DB::statement('ALTER TABLE erp_centros_costo DROP COLUMN auxiliar_id'); } catch (\Throwable) {}

        // Restaurar la columna que se eliminó (no se restauran datos).
        DB::statement("ALTER TABLE erp_facturas_compra
            ADD COLUMN periodo_pagado_texto VARCHAR(20) NULL
            COMMENT 'Periodo de servicio que paga, formato YYYY-MM' AFTER no_tomada");
    }

    private function addCol(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
    private function dropCol(string $table, string $column): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
        if ($exists) {
            // Drop indexes that reference this column first.
            $idx = DB::select(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND INDEX_NAME != "PRIMARY"',
                [$table, $column]
            );
            foreach ($idx as $row) {
                try { DB::statement("ALTER TABLE {$table} DROP INDEX {$row->INDEX_NAME}"); } catch (\Throwable) {}
            }
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
    private function indexExists(string $table, string $index): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1',
            [$table, $index]
        );
    }
    private function fkExists(string $table, string $fk): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE="FOREIGN KEY"',
            [$table, $fk]
        );
    }
};
