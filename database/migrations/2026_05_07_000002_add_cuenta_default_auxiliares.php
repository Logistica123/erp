<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.10 — Cuenta contable predeterminada por auxiliar.
 *
 * ALTER `erp_auxiliares`:
 *   + cuenta_contable_default_id BIGINT UNSIGNED NULL  (FK a erp_cuentas_contables)
 *
 * UPDATE inicial poblando el default según `tipo`:
 *   - Cliente       → 1.1.4.01 Deudores por Ventas
 *   - Distribuidor  → 2.1.1.03 Distribuidores a Pagar
 *   - Proveedor     → 2.1.1.01 Proveedores Comunes
 *   - Empleado      → 2.1.2.01 Sueldos a Pagar
 * Otros tipos (Socio, Vehiculo, etc) quedan NULL — no es operación de cuenta
 * contraparte estándar; el operador asigna a mano si los usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn('erp_auxiliares', 'cuenta_contable_default_id')) {
            DB::statement("ALTER TABLE erp_auxiliares
                ADD COLUMN cuenta_contable_default_id BIGINT UNSIGNED NULL
                  COMMENT 'Cuenta contable predeterminada para asientos contra este auxiliar.'
                  AFTER tipo");
        }
        if (! $this->indexExists('erp_auxiliares', 'idx_aux_cuenta_default')) {
            DB::statement('ALTER TABLE erp_auxiliares ADD INDEX idx_aux_cuenta_default (cuenta_contable_default_id)');
        }
        if (! $this->fkExists('erp_auxiliares', 'fk_aux_cuenta')) {
            DB::statement('ALTER TABLE erp_auxiliares
                ADD CONSTRAINT fk_aux_cuenta FOREIGN KEY (cuenta_contable_default_id)
                REFERENCES erp_cuentas_contables(id)');
        }

        // Migración inicial por tipo, idempotente (solo donde aún no se asignó).
        $mapping = [
            'Cliente'      => '1.1.4.01',
            'Distribuidor' => '2.1.1.03',
            'Proveedor'    => '2.1.1.01',
            'Empleado'     => '2.1.2.01',
        ];
        foreach ($mapping as $tipo => $codigo) {
            DB::statement("
                UPDATE erp_auxiliares aux
                  JOIN erp_cuentas_contables c ON c.codigo = ? AND c.empresa_id = aux.empresa_id
                  SET aux.cuenta_contable_default_id = c.id, aux.updated_at = NOW()
                WHERE aux.tipo = ? AND aux.cuenta_contable_default_id IS NULL
            ", [$codigo, $tipo]);
        }
    }

    public function down(): void
    {
        DB::statement('UPDATE erp_auxiliares SET cuenta_contable_default_id = NULL');
        try { DB::statement('ALTER TABLE erp_auxiliares DROP FOREIGN KEY fk_aux_cuenta'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_auxiliares DROP INDEX idx_aux_cuenta_default'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_auxiliares DROP COLUMN cuenta_contable_default_id'); } catch (\Throwable) {}
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
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
