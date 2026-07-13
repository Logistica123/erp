<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Item 8 Fase 2B tanda 2 (Sueldos) — 4 permisos que el catálogo SPEC 08
 * no tenía, con asignaciones espejo de sus pares existentes:
 *
 *  - sueldos.catalogos.ver     ≙ sueldos.empleados.ver   (lectura categorías/convenios/conceptos)
 *  - sueldos.catalogos.editar  ≙ definición de cálculo → contador_interno + rrhh
 *  - sueldos.prestamos.ver     ≙ sueldos.cc.ver          (lectura préstamos a empleados)
 *  - sueldos.reportes.ver      ≙ sueldos.efectivos.ver   (reportes salariales — sensible)
 *
 * Idempotente; down() borra exactamente lo listado (mismo patrón que
 * 2026_07_12_000003).
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string,3:string,4:int}> */
    private const PERMISOS_NUEVOS = [
        ['sueldos.catalogos.ver', 'sueldos', 'catalogos', 'ver', 0],
        ['sueldos.catalogos.editar', 'sueldos', 'catalogos', 'editar', 1],
        ['sueldos.prestamos.ver', 'sueldos', 'prestamos', 'ver', 0],
        ['sueldos.reportes.ver', 'sueldos', 'reportes', 'ver', 1],
    ];

    /** @var array<string, list<string>> */
    private const ASIGNACIONES = [
        'auditor' => ['sueldos.catalogos.ver', 'sueldos.prestamos.ver', 'sueldos.reportes.ver'],
        'contador_interno' => ['sueldos.catalogos.ver', 'sueldos.catalogos.editar', 'sueldos.prestamos.ver', 'sueldos.reportes.ver'],
        'direccion' => ['sueldos.catalogos.ver', 'sueldos.prestamos.ver', 'sueldos.reportes.ver'],
        'revisor_fiscal' => ['sueldos.catalogos.ver'],
        'rrhh' => ['sueldos.catalogos.ver', 'sueldos.catalogos.editar', 'sueldos.prestamos.ver'],
        'super_admin' => ['sueldos.catalogos.ver', 'sueldos.catalogos.editar', 'sueldos.prestamos.ver', 'sueldos.reportes.ver'],
    ];

    public function up(): void
    {
        foreach (self::PERMISOS_NUEVOS as [$codigo, $modulo, $entidad, $accion, $sensible]) {
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo, 'modulo' => $modulo, 'entidad' => $entidad,
                    'accion' => $accion, 'sensible' => $sensible,
                    'descripcion' => 'Item 8 Fase 2B tanda 2 — Sueldos (2026-07-13)',
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
        $codigos = array_map(fn ($p) => $p[0], self::PERMISOS_NUEVOS);
        $ids = DB::table('erp_permisos')->whereIn('codigo', $codigos)->pluck('id');
        DB::table('erp_rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('erp_permisos')->whereIn('id', $ids)->delete();
    }
};
