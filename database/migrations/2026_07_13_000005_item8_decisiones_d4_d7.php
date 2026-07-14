<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item 8 — decisiones D-4/D-5/D-6/D-7 con criterio conservador (pack
 * 2026-07-13, resueltas sin esperar a Sebastián):
 *
 *  D-4: reclasificaciones contables → contador (además de SA).
 *  D-5: revisor_fiscal SOLO valida/exporta → se le QUITAN generar/aprobar/editar.
 *  D-6: autorizar factura compra DistriApp es acción contable → códigos
 *       nuevos autorizar (contador) / desautorizar (admin_compras + contador).
 *  D-7: saldos iniciales cargar/revertir → también contador (con MFA).
 *
 * Idempotente; down() revierte exactamente lo hecho.
 */
return new class extends Migration
{
    private const PERMISOS_NUEVOS = [
        ['compras.facturas.autorizar', 'compras', 'facturas', 'autorizar', 1],
        ['compras.facturas.desautorizar', 'compras', 'facturas', 'desautorizar', 1],
    ];

    /** @var array<string, list<string>> altas por rol */
    private const ASIGNAR = [
        'contador' => [
            'contabilidad.iiddycc.reclasificar', 'contabilidad.pendientes.reclasificar', // D-4
            'tesoreria.saldos_iniciales.cargar', 'tesoreria.saldos_iniciales.revertir',   // D-7
            'compras.facturas.autorizar', 'compras.facturas.desautorizar',                // D-6
        ],
        'admin_compras' => [
            'compras.facturas.desautorizar', // D-6: revisa y rechaza, NO autoriza
        ],
        'super_admin' => [
            'compras.facturas.autorizar', 'compras.facturas.desautorizar',
        ],
    ];

    /** D-5: revisor_fiscal pierde todo lo que genera/aprueba/edita. */
    private const QUITAR_REVISOR_FISCAL = [
        'impuestos.iva.generar', 'impuestos.iibb.generar', 'impuestos.bp.generar',
        'impuestos.periodo.aprobar', 'impuestos.libro_iva.generar',
        'impuestos.sicore.generar', 'impuestos.ganancias.editar',
        'impuestos.ganancias.generar', 'eecc.generar', 'eecc.editar_notas',
    ];

    public function up(): void
    {
        foreach (self::PERMISOS_NUEVOS as [$codigo, $modulo, $entidad, $accion, $sensible]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo, 'modulo' => $modulo, 'entidad' => $entidad,
                    'accion' => $accion, 'sensible' => $sensible,
                    'descripcion' => 'Item 8 D-6 (decisiones 2026-07-13)',
                ]);
            }
        }

        foreach (self::ASIGNAR as $rolCodigo => $permisos) {
            $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
            if (! $rolId) {
                continue;
            }
            foreach ($permisos as $permisoCodigo) {
                $permisoId = DB::table('erp_permisos')->where('codigo', $permisoCodigo)->value('id');
                if (! $permisoId) {
                    continue;
                }
                if (! DB::table('erp_rol_permiso')->where('rol_id', $rolId)->where('permiso_id', $permisoId)->exists()) {
                    DB::table('erp_rol_permiso')->insert(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        }

        $revisorId = DB::table('erp_roles')->where('codigo', 'revisor_fiscal')->value('id');
        if ($revisorId) {
            $ids = DB::table('erp_permisos')->whereIn('codigo', self::QUITAR_REVISOR_FISCAL)->pluck('id');
            DB::table('erp_rol_permiso')->where('rol_id', $revisorId)->whereIn('permiso_id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Restaurar los permisos quitados al revisor_fiscal.
        $revisorId = DB::table('erp_roles')->where('codigo', 'revisor_fiscal')->value('id');
        if ($revisorId) {
            foreach (self::QUITAR_REVISOR_FISCAL as $codigo) {
                $permisoId = DB::table('erp_permisos')->where('codigo', $codigo)->value('id');
                if ($permisoId && ! DB::table('erp_rol_permiso')->where('rol_id', $revisorId)->where('permiso_id', $permisoId)->exists()) {
                    DB::table('erp_rol_permiso')->insert(['rol_id' => $revisorId, 'permiso_id' => $permisoId]);
                }
            }
        }

        // Quitar las asignaciones dadas de alta.
        foreach (self::ASIGNAR as $rolCodigo => $permisos) {
            $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
            if (! $rolId) {
                continue;
            }
            $ids = DB::table('erp_permisos')->whereIn('codigo', $permisos)->pluck('id');
            DB::table('erp_rol_permiso')->where('rol_id', $rolId)->whereIn('permiso_id', $ids)->delete();
        }

        // Borrar los permisos nuevos.
        $codigos = array_map(fn ($p) => $p[0], self::PERMISOS_NUEVOS);
        $ids = DB::table('erp_permisos')->whereIn('codigo', $codigos)->pluck('id');
        DB::table('erp_rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('erp_permisos')->whereIn('id', $ids)->delete();
    }
};
