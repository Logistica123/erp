<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC Conciliación Bancaria Multi-Banco — bloque CM-1.
 *
 * Cambios:
 *   1. ALTER erp_movimientos_bancarios — 8 columnas nuevas para guardar el
 *      resultado del MatchingContraparteService (CUIT, persona/cliente,
 *      cuenta propia, regla aplicada, confianza_match).
 *   2. ALTER erp_conciliacion_reglas — 5 columnas nuevas (banco_id,
 *      cod_concepto, signo, confianza, observacion) para reglas más finas.
 *      Se mantiene la columna `tipo` y `patron_concepto` actuales.
 *   3. CREATE erp_conciliacion_prefijos (catálogo CUIT/POLIZA por banco).
 *   4. CREATE erp_alias_contraparte (cache de asignaciones manuales).
 *   5. Seed: 15 prefijos ICBC + 5 permisos tesoreria.* asignados a roles.
 *
 * FK a personas/clientes son LÓGICAS — esas tablas viven en basepersonal
 * (otra DB). Se indexan pero sin constraint físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('migrations/sql/');

        // Detección runtime de tipos (prod tiene erp_bancos.id como INT
        // UNSIGNED, local lo tiene como BIGINT UNSIGNED). Las FKs nuevas
        // requieren tipos compatibles con la tabla referenciada.
        $tipoBanco  = $this->tipoFk('erp_bancos', 'id');
        $tipoCuenta = $this->tipoFk('erp_cuentas_bancarias', 'id');
        $tipoRegla  = $this->tipoFk('erp_conciliacion_reglas', 'id');

        // 1. Tablas nuevas — sustituyendo placeholders {{TIPO_BANCO}}.
        $sql = file_get_contents($path.'10_conciliacion_tables.sql');
        $sql = str_replace('{{TIPO_BANCO}}', $tipoBanco, $sql);
        DB::unprepared($sql);

        // 2. ALTER erp_movimientos_bancarios — 8 columnas nuevas idempotentes.
        $this->addCol('erp_movimientos_bancarios', 'cuit_contraparte',
            "VARCHAR(13) NULL COMMENT 'CUIT/CUIL extraído del extracto'");
        $this->addCol('erp_movimientos_bancarios', 'nombre_contraparte',
            "VARCHAR(200) NULL COMMENT 'Nombre/razón social tal como vino'");
        $this->addCol('erp_movimientos_bancarios', 'persona_id',
            "BIGINT UNSIGNED NULL COMMENT 'Match contra basepersonal.personas (FK lógica)'");
        $this->addCol('erp_movimientos_bancarios', 'cliente_id',
            "BIGINT UNSIGNED NULL COMMENT 'Match contra basepersonal.clientes (FK lógica)'");
        $this->addCol('erp_movimientos_bancarios', 'cuenta_propia_id',
            "{$tipoCuenta} NULL COMMENT 'Si es transf interna, otra cuenta_bancaria_id'");
        $this->addCol('erp_movimientos_bancarios', 'referencia_externa',
            "VARCHAR(120) NULL COMMENT 'Nro póliza / cuenta servicio / teléfono / etc.'");
        $this->addCol('erp_movimientos_bancarios', 'regla_aplicada_id',
            "{$tipoRegla} NULL COMMENT 'FK a erp_conciliacion_reglas que matcheó'");
        $this->addCol('erp_movimientos_bancarios', 'confianza_match',
            "TINYINT UNSIGNED NULL COMMENT '0-100. ≥80 auto-conciliable; 50-79 propuesto; <50 manual'");

        if (! $this->indexExists('erp_movimientos_bancarios', 'idx_cuit_contraparte')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios ADD INDEX idx_cuit_contraparte (cuit_contraparte)');
        }
        if (! $this->indexExists('erp_movimientos_bancarios', 'idx_persona_id')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios ADD INDEX idx_persona_id (persona_id)');
        }
        if (! $this->indexExists('erp_movimientos_bancarios', 'idx_estado_confianza')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios ADD INDEX idx_estado_confianza (estado, confianza_match)');
        }
        if (! $this->fkExists('erp_movimientos_bancarios', 'fk_mov_cta_propia')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios ADD CONSTRAINT fk_mov_cta_propia
                FOREIGN KEY (cuenta_propia_id) REFERENCES erp_cuentas_bancarias(id)');
        }
        if (! $this->fkExists('erp_movimientos_bancarios', 'fk_mov_regla')) {
            DB::statement('ALTER TABLE erp_movimientos_bancarios ADD CONSTRAINT fk_mov_regla
                FOREIGN KEY (regla_aplicada_id) REFERENCES erp_conciliacion_reglas(id)');
        }

        // 3. ALTER erp_conciliacion_reglas — 5 columnas nuevas.
        $this->addCol('erp_conciliacion_reglas', 'banco_id',
            "{$tipoBanco} NULL COMMENT 'NULL = aplica a todos los bancos'");
        $this->addCol('erp_conciliacion_reglas', 'cod_concepto',
            "VARCHAR(10) NULL COMMENT 'Filtro adicional por código concepto (ICBC)'");
        $this->addCol('erp_conciliacion_reglas', 'signo',
            "ENUM('DEBITO','CREDITO','AMBOS') NOT NULL DEFAULT 'AMBOS'");
        $this->addCol('erp_conciliacion_reglas', 'confianza',
            "TINYINT UNSIGNED NOT NULL DEFAULT 80 COMMENT '0-100'");
        $this->addCol('erp_conciliacion_reglas', 'observacion', 'TEXT NULL');

        if (! $this->indexExists('erp_conciliacion_reglas', 'idx_regla_banco')) {
            DB::statement('ALTER TABLE erp_conciliacion_reglas ADD INDEX idx_regla_banco (empresa_id, banco_id, activa)');
        }
        if (! $this->fkExists('erp_conciliacion_reglas', 'fk_regla_banco')) {
            DB::statement('ALTER TABLE erp_conciliacion_reglas ADD CONSTRAINT fk_regla_banco
                FOREIGN KEY (banco_id) REFERENCES erp_bancos(id)');
        }

        // 4. Seed prefijos ICBC + permisos.
        DB::unprepared(file_get_contents($path.'10_conciliacion_seed.sql'));
    }

    public function down(): void
    {
        // Permisos
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos
             WHERE codigo IN (
              'tesoreria.extractos.importar','tesoreria.movimientos.conciliar',
              'tesoreria.movimientos.ignorar','tesoreria.reglas.ver','tesoreria.reglas.gestionar'
            )
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo IN (
          'tesoreria.extractos.importar','tesoreria.movimientos.conciliar',
          'tesoreria.movimientos.ignorar','tesoreria.reglas.ver','tesoreria.reglas.gestionar'
        )");

        // Tablas nuevas
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('DROP TABLE IF EXISTS erp_alias_contraparte');
        DB::statement('DROP TABLE IF EXISTS erp_conciliacion_prefijos');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ALTERs
        foreach (['fk_mov_regla','fk_mov_cta_propia'] as $fk) {
            try { DB::statement("ALTER TABLE erp_movimientos_bancarios DROP FOREIGN KEY {$fk}"); } catch (\Throwable) {}
        }
        foreach (['cuit_contraparte','nombre_contraparte','persona_id','cliente_id','cuenta_propia_id','referencia_externa','regla_aplicada_id','confianza_match'] as $c) {
            try { DB::statement("ALTER TABLE erp_movimientos_bancarios DROP COLUMN {$c}"); } catch (\Throwable) {}
        }
        try { DB::statement('ALTER TABLE erp_conciliacion_reglas DROP FOREIGN KEY fk_regla_banco'); } catch (\Throwable) {}
        foreach (['banco_id','cod_concepto','signo','confianza','observacion'] as $c) {
            try { DB::statement("ALTER TABLE erp_conciliacion_reglas DROP COLUMN {$c}"); } catch (\Throwable) {}
        }
    }

    /**
     * Devuelve el COLUMN_TYPE de una columna referenciada (ej. erp_bancos.id) en
     * la base actual, normalizado a uno de: 'BIGINT UNSIGNED' / 'INT UNSIGNED'.
     * Sirve para que las nuevas FKs declaren columnas con un tipo compatible
     * con el de la tabla referenciada (prod = INT UNSIGNED, local = BIGINT).
     */
    private function tipoFk(string $tabla, string $columna): string
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );
        $type = strtolower((string) ($row?->COLUMN_TYPE ?? 'bigint unsigned'));
        $unsigned = str_contains($type, 'unsigned') ? ' UNSIGNED' : '';
        if (str_contains($type, 'bigint')) return 'BIGINT'.$unsigned;
        if (str_contains($type, 'int'))    return 'INT'.$unsigned;
        return strtoupper($type);
    }

    private function addCol(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $r = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );
        return (bool) $r;
    }

    private function fkExists(string $table, string $fk): bool
    {
        $r = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $fk, 'FOREIGN KEY']
        );
        return (bool) $r;
    }
};
