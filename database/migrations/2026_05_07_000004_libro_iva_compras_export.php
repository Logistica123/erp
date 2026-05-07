<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.11 — Generador de TXT F.8001 Libro IVA Digital Compras.
 *
 *   1. CREATE erp_libros_iva_compras_export: tracking de cada generación
 *      con hash SHA-256 de cada archivo, totales, marca de envío AFIP.
 *      Re-generar es OK: cada generación queda registrada (no se duplica).
 *
 *   2. INSERT permiso `compras.exportar_libro_iva_periodo_cerrado` para
 *      que solo super_admin/contador puedan generar TXT de períodos cerrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('erp_libros_iva_compras_export')) {
            DB::statement("CREATE TABLE erp_libros_iva_compras_export (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                empresa_id BIGINT UNSIGNED NOT NULL,
                periodo_id BIGINT UNSIGNED NOT NULL,
                archivo_cbte_path VARCHAR(500) NOT NULL,
                archivo_alicuotas_path VARCHAR(500) NOT NULL,
                archivo_cbte_hash CHAR(64) NOT NULL,
                archivo_alicuotas_hash CHAR(64) NOT NULL,
                filas_cbte INT UNSIGNED NOT NULL,
                filas_alicuotas INT UNSIGNED NOT NULL,
                total_neto DECIMAL(18,2) NOT NULL DEFAULT 0,
                total_iva DECIMAL(18,2) NOT NULL DEFAULT 0,
                total_facturas DECIMAL(18,2) NOT NULL DEFAULT 0,
                generado_por BIGINT UNSIGNED NOT NULL,
                generado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                enviado_afip TINYINT(1) NOT NULL DEFAULT 0,
                enviado_at DATETIME NULL,
                enviado_por BIGINT UNSIGNED NULL,
                observaciones TEXT NULL,
                INDEX idx_periodo (periodo_id),
                INDEX idx_generado_at (generado_at),
                CONSTRAINT fk_exp_empresa FOREIGN KEY (empresa_id) REFERENCES erp_empresas(id),
                CONSTRAINT fk_exp_periodo FOREIGN KEY (periodo_id) REFERENCES erp_periodos(id),
                CONSTRAINT fk_exp_user FOREIGN KEY (generado_por) REFERENCES users(id),
                CONSTRAINT fk_exp_user_envio FOREIGN KEY (enviado_por) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (! DB::table('erp_permisos')->where('codigo', 'compras.exportar_libro_iva_periodo_cerrado')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'compras.exportar_libro_iva_periodo_cerrado',
                'modulo' => 'compras',
                'entidad' => 'libro_iva_export',
                'accion' => 'exportar_periodo_cerrado',
                'descripcion' => 'Permite generar TXT F.8001 para períodos cerrados.',
                'sensible' => 1,
            ]);
        }
        $permId = DB::table('erp_permisos')->where('codigo', 'compras.exportar_libro_iva_periodo_cerrado')->value('id');
        $roles = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
        foreach ($roles as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permId],
                ['rol_id' => $rolId, 'permiso_id' => $permId]
            );
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo = 'compras.exportar_libro_iva_periodo_cerrado')");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'compras.exportar_libro_iva_periodo_cerrado'");
        DB::statement('DROP TABLE IF EXISTS erp_libros_iva_compras_export');
    }

    private function tableExists(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        );
    }
};
