<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anexo Cierres Diarios CB-2 — habilita parsers Brubank.
 *
 * Brubank exporta el extracto con DOS cuentas en el mismo archivo (Cuenta
 * corriente + Cuenta remunerada). Cada cuenta se importa como un extracto
 * independiente filtrando por la columna `Cuenta` del CSV. La UK actual de
 * erp_extractos_bancarios sobre `hash_archivo` solo bloquea: el mismo
 * archivo no se puede usar para 2 cuentas.
 *
 * Cambio: UK pasa a (cuenta_bancaria_id, hash_archivo). Sigue garantizando
 * idempotencia (mismo archivo + misma cuenta = duplicado), pero permite
 * subir el mismo CSV de Brubank a 2 cuentas distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop UK previa si existe.
        if ($this->indexExists('erp_extractos_bancarios', 'uk_extractos_hash')) {
            DB::statement('ALTER TABLE erp_extractos_bancarios DROP INDEX uk_extractos_hash');
        }
        // Crear UK nueva si no existe.
        if (! $this->indexExists('erp_extractos_bancarios', 'uk_extractos_cuenta_hash')) {
            DB::statement('ALTER TABLE erp_extractos_bancarios
                ADD CONSTRAINT uk_extractos_cuenta_hash
                UNIQUE (cuenta_bancaria_id, hash_archivo)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('erp_extractos_bancarios', 'uk_extractos_cuenta_hash')) {
            DB::statement('ALTER TABLE erp_extractos_bancarios DROP INDEX uk_extractos_cuenta_hash');
        }
        if (! $this->indexExists('erp_extractos_bancarios', 'uk_extractos_hash')) {
            DB::statement('ALTER TABLE erp_extractos_bancarios
                ADD CONSTRAINT uk_extractos_hash UNIQUE (hash_archivo)');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );
        return (bool) $row;
    }
};
