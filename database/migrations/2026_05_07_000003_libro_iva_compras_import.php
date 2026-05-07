<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.9 (reescrito) — Import enriquecido del Libro IVA Compras.
 *
 * Cambios:
 *   1. ALTER erp_facturas_compra: agrega 5 columnas para guardar la info que
 *      el contador agrega al CSV de AFIP en su Excel:
 *        - no_tomada              (Tomado=NO no impacta contable)
 *        - cliente_auxiliar_id    (FK a erp_auxiliares, bridge a clientes)
 *        - periodo_pagado_texto   (período de servicio que paga la factura)
 *        - tipo_gasto             (texto libre, sin tabla maestra)
 *        - import_id              (FK al archivo de import)
 *
 *   2. CREATE erp_libros_iva_compras_import: tracking de cada archivo
 *      importado, con hash para idempotencia, conteo de filas, errores y
 *      clientes no mapeados.
 *
 *   3. ALTER erp_alicuotas_iva: agrega codigo_afip (4 chars) con seed.
 *      Sirve para el import (deduce alícuota a partir del código del CSV)
 *      y prepara el v1.11 (generador F.8001) que lo necesita en ALICUOTAS.txt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----- 1. ALTER erp_facturas_compra ----------------------------------
        $this->addCol('erp_facturas_compra', 'no_tomada',
            "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Tomado=NO en Excel del contador. No genera asiento.'");
        $this->addCol('erp_facturas_compra', 'cliente_auxiliar_id',
            "BIGINT UNSIGNED NULL COMMENT 'Cliente al que se le presta servicio (bridge via erp_auxiliares.tipo=Cliente)'");
        $this->addCol('erp_facturas_compra', 'periodo_pagado_texto',
            "VARCHAR(20) NULL COMMENT 'Periodo de servicio que paga, formato YYYY-MM'");
        $this->addCol('erp_facturas_compra', 'tipo_gasto',
            "VARCHAR(80) NULL COMMENT 'Texto libre del contador: Combustible, Peajes, etc.'");
        $this->addCol('erp_facturas_compra', 'import_id',
            "BIGINT UNSIGNED NULL COMMENT 'FK al archivo de import del cual provino'");

        if (! $this->indexExists('erp_facturas_compra', 'idx_no_tomada')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_no_tomada (no_tomada)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_cliente_aux')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_cliente_aux (cliente_auxiliar_id)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_tipo_gasto')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_tipo_gasto (tipo_gasto)');
        }
        if (! $this->indexExists('erp_facturas_compra', 'idx_import_fc')) {
            DB::statement('ALTER TABLE erp_facturas_compra ADD INDEX idx_import_fc (import_id)');
        }
        if (! $this->fkExists('erp_facturas_compra', 'fk_compra_cliente_aux')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT fk_compra_cliente_aux FOREIGN KEY (cliente_auxiliar_id)
                REFERENCES erp_auxiliares(id)');
        }

        // ----- 2. CREATE erp_libros_iva_compras_import -----------------------
        if (! $this->tableExists('erp_libros_iva_compras_import')) {
            DB::statement("CREATE TABLE erp_libros_iva_compras_import (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                empresa_id BIGINT UNSIGNED NOT NULL,
                archivo_nombre VARCHAR(255) NOT NULL,
                archivo_hash CHAR(64) NOT NULL,
                periodo_afip CHAR(6) NULL COMMENT 'Periodo declarado en el nombre del archivo, YYYYMM',
                periodo_imputacion_id BIGINT UNSIGNED NOT NULL,
                filas_totales INT UNSIGNED NOT NULL DEFAULT 0,
                filas_tomadas INT UNSIGNED NOT NULL DEFAULT 0,
                filas_no_tomadas INT UNSIGNED NOT NULL DEFAULT 0,
                filas_skipped INT UNSIGNED NOT NULL DEFAULT 0,
                filas_error INT UNSIGNED NOT NULL DEFAULT 0,
                errores_detalle JSON NULL,
                clientes_no_mapeados JSON NULL,
                proveedores_creados INT UNSIGNED NOT NULL DEFAULT 0,
                importado_por BIGINT UNSIGNED NOT NULL,
                importado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                estado ENUM('PROCESANDO','COMPLETO','PARCIAL','ERROR') NOT NULL DEFAULT 'PROCESANDO',
                UNIQUE KEY uq_empresa_hash (empresa_id, archivo_hash),
                INDEX idx_periodo_imputacion (periodo_imputacion_id),
                INDEX idx_importado_at (importado_at),
                CONSTRAINT fk_imp_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
                CONSTRAINT fk_imp_periodo FOREIGN KEY (periodo_imputacion_id) REFERENCES erp_periodos(id),
                CONSTRAINT fk_imp_user FOREIGN KEY (importado_por) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // FK retroactiva facturas_compra.import_id -> import.id
        if (! $this->fkExists('erp_facturas_compra', 'fk_compra_import')) {
            DB::statement('ALTER TABLE erp_facturas_compra
                ADD CONSTRAINT fk_compra_import FOREIGN KEY (import_id)
                REFERENCES erp_libros_iva_compras_import(id)');
        }

        // ----- 3. ALTER erp_alicuotas_iva con codigo_afip --------------------
        $this->addCol('erp_alicuotas_iva', 'codigo_afip',
            "CHAR(4) NULL COMMENT 'Código AFIP de la alícuota: 0003=10.5%, 0005=21%, etc.'");

        // Seed códigos AFIP (idempotente).
        $codigosAfip = [
            'IVA_0'    => '0009',  // IVA 0%
            'IVA_2_5'  => '0006',  // IVA 2.5%
            'IVA_5'    => '0008',  // IVA 5%
            'IVA_10_5' => '0003',  // IVA 10.5%
            'IVA_21'   => '0005',  // IVA 21%
            'IVA_27'   => '0004',  // IVA 27%
        ];
        foreach ($codigosAfip as $codigoInterno => $codAfip) {
            DB::table('erp_alicuotas_iva')
                ->where('codigo_interno', $codigoInterno)
                ->whereNull('codigo_afip')
                ->update(['codigo_afip' => $codAfip]);
        }
    }

    public function down(): void
    {
        try { DB::statement('ALTER TABLE erp_facturas_compra DROP FOREIGN KEY fk_compra_import'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_facturas_compra DROP FOREIGN KEY fk_compra_cliente_aux'); } catch (\Throwable) {}
        DB::statement('DROP TABLE IF EXISTS erp_libros_iva_compras_import');
        foreach (['idx_no_tomada','idx_cliente_aux','idx_tipo_gasto','idx_import_fc'] as $idx) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP INDEX {$idx}"); } catch (\Throwable) {}
        }
        foreach (['no_tomada','cliente_auxiliar_id','periodo_pagado_texto','tipo_gasto','import_id'] as $col) {
            try { DB::statement("ALTER TABLE erp_facturas_compra DROP COLUMN {$col}"); } catch (\Throwable) {}
        }
        try { DB::statement('ALTER TABLE erp_alicuotas_iva DROP COLUMN codigo_afip'); } catch (\Throwable) {}
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
    private function tableExists(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        );
    }
};
