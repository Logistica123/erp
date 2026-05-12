<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.17 — Carga manual de facturas (Ventas + Compras).
 *
 *   1. ALTER erp_facturas_venta y erp_facturas_compra:
 *        +verificada_arca TINYINT(1)
 *        +verificada_arca_at DATETIME
 *        +verificacion_resultado JSON
 *      (Las columnas `origen` ya soportan MANUAL/DISTRIAPP en sus ENUMs, no
 *      requieren modificación.)
 *
 *   2. INSERT 3 permisos nuevos:
 *        ventas.facturas.cargar_manual (sensible=1) → super_admin + contador + facturador
 *        compras.facturas.cargar_manual (sensible=1) → super_admin + contador
 *        facturas.verificar_arca (sensible=0) → super_admin + contador + facturador
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_facturas_venta', 'erp_facturas_compra'] as $tabla) {
            $this->addCol($tabla, 'verificada_arca',
                "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'v1.17 — TRUE si constatada contra WSCDC + padrón A5/A13.'");
            $this->addCol($tabla, 'verificada_arca_at',
                "DATETIME NULL COMMENT 'Timestamp de la última verificación.'");
            $this->addCol($tabla, 'verificacion_resultado',
                "JSON NULL COMMENT 'Resultado de la constatación: {cae_valido, cuit_valido, padron_estado, motivo_rechazo}.'");
        }

        $permisos = [
            ['ventas.facturas.cargar_manual',
             'Permite registrar facturas de venta sin emitir contra ARCA (carga manual).',
             'ventas', 'facturas', 'cargar_manual', 1,
             ['super_admin', 'contador', 'facturador']],
            ['compras.facturas.cargar_manual',
             'Permite cargar facturas de compra a mano (sin pasar por el import del Libro IVA).',
             'compras', 'facturas', 'cargar_manual', 1,
             ['super_admin', 'contador']],
            ['facturas.verificar_arca',
             'Permite ejecutar la constatación opcional contra ARCA (WSCDC + padrón A5/A13).',
             'general', 'facturas', 'verificar_arca', 0,
             ['super_admin', 'contador', 'facturador']],
        ];

        foreach ($permisos as [$codigo, $desc, $modulo, $entidad, $accion, $sensible, $roles]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo,
                    'modulo' => $modulo,
                    'entidad' => $entidad,
                    'accion' => $accion,
                    'descripcion' => $desc,
                    'sensible' => $sensible,
                ]);
            }
            $permId = DB::table('erp_permisos')->where('codigo', $codigo)->value('id');
            $rolIds = DB::table('erp_roles')->whereIn('codigo', $roles)->pluck('id');
            foreach ($rolIds as $rolId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $rolId, 'permiso_id' => $permId],
                    ['rol_id' => $rolId, 'permiso_id' => $permId]
                );
            }
        }
    }

    public function down(): void
    {
        $codigos = ['ventas.facturas.cargar_manual', 'compras.facturas.cargar_manual', 'facturas.verificar_arca'];
        foreach ($codigos as $codigo) {
            DB::statement('DELETE FROM erp_rol_permiso WHERE permiso_id IN (SELECT id FROM erp_permisos WHERE codigo = ?)', [$codigo]);
            DB::statement('DELETE FROM erp_permisos WHERE codigo = ?', [$codigo]);
        }
        foreach (['erp_facturas_venta', 'erp_facturas_compra'] as $tabla) {
            foreach (['verificacion_resultado', 'verificada_arca_at', 'verificada_arca'] as $col) {
                try { DB::statement("ALTER TABLE {$tabla} DROP COLUMN {$col}"); } catch (\Throwable) {}
            }
        }
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
};
