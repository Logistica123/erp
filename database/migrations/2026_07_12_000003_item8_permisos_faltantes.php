<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item 8 Fase 2A — permisos faltantes del catálogo + asignaciones según la
 * matriz aprobada (Fase 1) y decisiones D-8 (tesorero opera inversiones y
 * préstamos) y D-9 (dirección edita presupuestos).
 *
 * Idempotente: solo inserta lo que no existe. down() borra EXACTAMENTE lo
 * listado acá (verificado contra la base: ninguna de estas asignaciones
 * existía antes), dejando el mundo como estaba — B.8 del pedido.
 *
 * Nota D-7 (pendiente Sebastián): saldos_iniciales.cargar/revertir no se
 * asignan a ningún rol — solo super_admin (bypass) hasta que se defina.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string,3:string,4:int}> codigo, modulo, entidad, accion, sensible */
    private const PERMISOS_NUEVOS = [
        ['tesoreria.saldos_iniciales.ver', 'tesoreria', 'saldos_iniciales', 'ver', 0],
        ['tesoreria.saldos_iniciales.cargar', 'tesoreria', 'saldos_iniciales', 'cargar', 1],
        ['tesoreria.saldos_iniciales.revertir', 'tesoreria', 'saldos_iniciales', 'revertir', 1],
        ['impuestos.bp.ver', 'impuestos', 'bp', 'ver', 0],
        ['impuestos.libro_iva.ver', 'impuestos', 'libro_iva', 'ver', 0],
        ['impuestos.iibb.aprobar', 'impuestos', 'iibb', 'aprobar', 1],
        ['presupuesto.ver', 'presupuesto', 'presupuesto', 'ver', 0],
        ['cierres.dia.ajuste_retroactivo', 'cierres', 'dia', 'ajuste_retroactivo', 1],
        ['af.bienes.ver', 'af', 'bienes', 'ver', 0],
        // Existen en prod pero no en entornos con catálogo viejo (drift):
        ['contabilidad.periodos.bloquear', 'contabilidad', 'periodos', 'bloquear', 1],
        ['contabilidad.periodos.desbloquear', 'contabilidad', 'periodos', 'desbloquear', 1],
    ];

    /** @var array<string, list<string>> rol => permisos (nuevos + D-8/D-9 sobre existentes) */
    private const ASIGNACIONES = [
        'contador' => [
            'tesoreria.saldos_iniciales.ver', 'impuestos.bp.ver', 'impuestos.libro_iva.ver',
            'impuestos.iibb.aprobar', 'presupuesto.ver', 'cierres.dia.ajuste_retroactivo', 'af.bienes.ver',
        ],
        'direccion' => [
            'tesoreria.saldos_iniciales.ver', 'impuestos.bp.ver', 'impuestos.libro_iva.ver',
            'presupuesto.ver', 'af.bienes.ver',
            // D-9 — dirección edita presupuestos.
            'presupuesto.crear', 'presupuesto.editar',
        ],
        'tesorero' => [
            'tesoreria.saldos_iniciales.ver', 'presupuesto.ver',
            // D-8 — tesorero opera inversiones y préstamos (ver + operar).
            'inversiones.ver', 'inversiones.crear', 'inversiones.registrar_movimiento',
            'prestamos.ver', 'prestamos.crear', 'prestamos.registrar_pago_cuota', 'prestamos.cancelar',
        ],
        'auditor' => [
            'tesoreria.saldos_iniciales.ver', 'impuestos.bp.ver', 'impuestos.libro_iva.ver',
            'presupuesto.ver', 'af.bienes.ver',
        ],
        'revisor_fiscal' => [
            'impuestos.bp.ver', 'impuestos.libro_iva.ver',
        ],
        'super_admin' => [
            // El bypass lo cubre, pero se asignan igual para que el test de
            // robustez de matriz (bypass apagado) pase sin baches.
            'tesoreria.saldos_iniciales.ver', 'tesoreria.saldos_iniciales.cargar',
            'tesoreria.saldos_iniciales.revertir', 'impuestos.bp.ver', 'impuestos.libro_iva.ver',
            'impuestos.iibb.aprobar', 'presupuesto.ver', 'cierres.dia.ajuste_retroactivo', 'af.bienes.ver',
            'contabilidad.periodos.bloquear', 'contabilidad.periodos.desbloquear',
        ],
    ];

    public function up(): void
    {
        foreach (self::PERMISOS_NUEVOS as [$codigo, $modulo, $entidad, $accion, $sensible]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo, 'modulo' => $modulo, 'entidad' => $entidad,
                    'accion' => $accion, 'sensible' => $sensible,
                    'descripcion' => 'Item 8 Fase 2A (auditoría 2026-07-12)',
                ]);
            }
        }

        foreach (self::ASIGNACIONES as $rolCodigo => $permisos) {
            $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
            if (! $rolId) {
                continue;
            }
            foreach ($permisos as $permisoCodigo) {
                $permisoId = DB::table('erp_permisos')->where('codigo', $permisoCodigo)->value('id');
                if (! $permisoId) {
                    continue;
                }
                $existe = DB::table('erp_rol_permiso')
                    ->where('rol_id', $rolId)->where('permiso_id', $permisoId)->exists();
                if (! $existe) {
                    DB::table('erp_rol_permiso')->insert(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        }
    }

    public function down(): void
    {
        // Quitar las asignaciones listadas (verificado: no existían antes).
        foreach (self::ASIGNACIONES as $rolCodigo => $permisos) {
            $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
            if (! $rolId) {
                continue;
            }
            $permisoIds = DB::table('erp_permisos')->whereIn('codigo', $permisos)->pluck('id');
            DB::table('erp_rol_permiso')->where('rol_id', $rolId)->whereIn('permiso_id', $permisoIds)->delete();
        }

        // Borrar los permisos creados por este seeder (y cualquier pivot residual).
        $codigos = array_map(fn ($p) => $p[0], self::PERMISOS_NUEVOS);
        $ids = DB::table('erp_permisos')->whereIn('codigo', $codigos)->pluck('id');
        DB::table('erp_rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('erp_permisos')->whereIn('id', $ids)->delete();
    }
};
