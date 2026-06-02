<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.37 — Permisos del reporte de saldos consolidados + manejo de categoría
 * EFECTIVO en facturas.
 *
 *   reportes.saldos_consolidados.ver           → ver totales y aging
 *   reportes.saldos_consolidados.ver_efectivo  → ver desglose "de los cuales en efectivo"
 *   reportes.saldos_consolidados.export        → export XLSX / PDF (Fase 2.4)
 *   facturas.editar_categoria                  → cambiar categoría post-creación
 *   facturas.crear_efectivo                    → crear factura tipo EFECTIVO
 *
 * El permiso ver_efectivo está separado del ver general porque el desglose
 * de operaciones de gestión es sensible — debe restringirse a contador,
 * super_admin y director (D-37 §10).
 *
 * Asignación inicial: super_admin recibe todos. Otros roles los configura el
 * super_admin desde el panel de permisos.
 */
return new class extends Migration
{
    private const PERMISOS = [
        [
            'codigo' => 'reportes.saldos_consolidados.ver',
            'modulo' => 'reportes', 'entidad' => 'saldos_consolidados', 'accion' => 'ver',
            'descripcion' => 'Ver el reporte de saldos consolidados (Deudores por ventas + Deuda con proveedores, aging, top deudores/acreedores).',
            'sensible' => 0,
        ],
        [
            'codigo' => 'reportes.saldos_consolidados.ver_efectivo',
            'modulo' => 'reportes', 'entidad' => 'saldos_consolidados', 'accion' => 'ver_efectivo',
            'descripcion' => 'Ver el desglose "de los cuales en efectivo" del reporte de saldos consolidados. Sin este permiso, el reporte muestra solo el total general.',
            'sensible' => 1,
        ],
        [
            'codigo' => 'reportes.saldos_consolidados.export',
            'modulo' => 'reportes', 'entidad' => 'saldos_consolidados', 'accion' => 'export',
            'descripcion' => 'Exportar el reporte de saldos consolidados a Excel / PDF.',
            'sensible' => 0,
        ],
        [
            'codigo' => 'facturas.editar_categoria',
            'modulo' => 'ventas_compras', 'entidad' => 'facturas', 'accion' => 'editar_categoria',
            'descripcion' => 'Cambiar la categoría (FACTURA ⇄ EFECTIVO) de una factura ya creada. Solo si no tiene asientos contables firmes ni recibos imputados.',
            'sensible' => 2,
        ],
        [
            'codigo' => 'facturas.crear_efectivo',
            'modulo' => 'ventas_compras', 'entidad' => 'facturas', 'accion' => 'crear_efectivo',
            'descripcion' => 'Crear una factura tipo EFECTIVO (operación de gestión sin factura fiscal). No afecta Libro IVA ni F.8001/F.2002.',
            'sensible' => 1,
        ],
    ];

    public function up(): void
    {
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');

        foreach (self::PERMISOS as $p) {
            DB::table('erp_permisos')->updateOrInsert(
                ['codigo' => $p['codigo']],
                $p,
            );
            $permId = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($superAdminId && $permId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $superAdminId, 'permiso_id' => $permId], [],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::PERMISOS as $p) {
            $permId = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($permId) {
                DB::table('erp_rol_permiso')->where('permiso_id', $permId)->delete();
                DB::table('erp_permisos')->where('id', $permId)->delete();
            }
        }
    }
};
