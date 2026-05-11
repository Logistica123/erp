<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 ampliación 2026-05-10 — CRUD de Centros de Costo.
 *
 *   GET    /api/erp/centros-costo            listado con filtros
 *   POST   /api/erp/centros-costo            crear (solo tipo != CLIENTE)
 *   PUT    /api/erp/centros-costo/{id}       editar (reglas por tipo)
 *   DELETE /api/erp/centros-costo/{id}       soft delete
 *   POST   /api/erp/centros-costo/{id}/reactivar
 *
 * Reglas (CC-10):
 *  - Nombre: editable libremente todos los tipos.
 *  - Código: read-only para CLIENTE; editable en MANUAL solo si no tiene movimientos.
 *  - cliente_id (auxiliar_id en nuestra impl): read-only siempre.
 *  - tipo=CLIENTE: no se puede crear/eliminar manualmente, se gestionan via observer.
 */
class CentrosCostoController
{
    private const TIPOS_MANUAL = ['GENERAL', 'PROYECTO', 'SUCURSAL', 'OTRO'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->empresaId($request);
        $tipo = trim((string) $request->query('tipo', ''));
        $q = trim((string) $request->query('q', ''));
        $incluirInactivos = $request->boolean('incluir_inactivos');

        $query = DB::table('erp_centros_costo as cc')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'cc.auxiliar_id')
            ->where('cc.empresa_id', $empresaId);

        if (! $incluirInactivos) {
            $query->where('cc.activo', 1);
        }
        if ($tipo !== '') {
            $query->where('cc.tipo', $tipo);
        }
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('cc.codigo', 'like', "%{$q}%")
                    ->orWhere('cc.nombre', 'like', "%{$q}%");
            });
        }

        $rows = $query->orderBy('cc.codigo')
            ->select(
                'cc.id', 'cc.codigo', 'cc.nombre', 'cc.tipo', 'cc.activo',
                'cc.auxiliar_id', 'a.nombre as auxiliar_nombre',
                'cc.created_at', 'cc.eliminada_at', 'cc.reactivada_at',
                'cc.observaciones',
                DB::raw('(SELECT COUNT(*) FROM erp_movimientos_asiento WHERE centro_costo_id = cc.id) as movimientos_count')
            )
            ->limit(500)
            ->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->permiso($request, 'contabilidad.centros_costo.crear')) {
            return $this->sinPermiso();
        }
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:30'],
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', 'string'],
            'observaciones' => ['nullable', 'string'],
        ]);

        if (! in_array($data['tipo'], self::TIPOS_MANUAL, true)) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'TIPO_INVALIDO',
                    'message' => 'Los CC tipo CLIENTE se crean automáticamente al dar de alta un cliente. Usá un tipo manual: '.implode(', ', self::TIPOS_MANUAL)],
            ], 422);
        }

        $empresaId = $this->empresaId($request);
        if (DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', $data['codigo'])->exists()) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CODIGO_DUPLICADO', 'message' => "Ya existe un CC con código {$data['codigo']}."],
            ], 422);
        }

        $id = DB::table('erp_centros_costo')->insertGetId([
            'empresa_id' => $empresaId,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'auxiliar_id' => null,
            'activo' => 1,
            'observaciones' => $data['observaciones'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'CC_CREADO',
            modulo: 'contabilidad',
            descripcion: "CC manual creado: {$data['codigo']} — {$data['nombre']} (tipo {$data['tipo']})",
            empresaId: $empresaId,
        );

        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->permiso($request, 'contabilidad.centros_costo.editar')) {
            return $this->sinPermiso();
        }
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:150'],
            'codigo' => ['nullable', 'string', 'max:30'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $empresaId = $this->empresaId($request);
        $cc = DB::table('erp_centros_costo')->where('id', $id)->where('empresa_id', $empresaId)->first();
        if (! $cc) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }

        $update = [];
        if (isset($data['nombre']) && $data['nombre'] !== '') {
            $update['nombre'] = $data['nombre'];
        }
        if (array_key_exists('observaciones', $data)) {
            $update['observaciones'] = $data['observaciones'];
        }
        if (isset($data['codigo']) && $data['codigo'] !== $cc->codigo) {
            // CC-10: código solo editable para CC manuales sin movimientos.
            if ($cc->tipo === 'CLIENTE') {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'CODIGO_READONLY',
                        'message' => 'El código de un CC tipo CLIENTE es read-only — se regenera automáticamente si cambia el nombre del cliente.'],
                ], 422);
            }
            $movs = DB::table('erp_movimientos_asiento')->where('centro_costo_id', $cc->id)->count();
            if ($movs > 0) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'CODIGO_CON_MOVIMIENTOS',
                        'message' => "No se puede cambiar el código: el CC tiene {$movs} movimiento(s). Editá el nombre si necesitás renombrar."],
                ], 422);
            }
            if (DB::table('erp_centros_costo')
                ->where('empresa_id', $empresaId)
                ->where('codigo', $data['codigo'])
                ->where('id', '!=', $cc->id)
                ->exists()) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'CODIGO_DUPLICADO', 'message' => "Ya existe un CC con código {$data['codigo']}."],
                ], 422);
            }
            $update['codigo'] = $data['codigo'];
        }

        if (! empty($update)) {
            $update['updated_at'] = now();
            DB::table('erp_centros_costo')->where('id', $id)->update($update);
        }

        return response()->json(['ok' => true, 'data' => ['id' => $id]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->permiso($request, 'contabilidad.centros_costo.eliminar')) {
            return $this->sinPermiso();
        }
        $empresaId = $this->empresaId($request);
        $cc = DB::table('erp_centros_costo')->where('id', $id)->where('empresa_id', $empresaId)->first();
        if (! $cc) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        if ($cc->tipo === 'CLIENTE') {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CC_CLIENTE_NO_ELIMINABLE',
                    'message' => 'Los CC tipo CLIENTE se desactivan automáticamente al dar de baja al cliente, no manualmente.'],
            ], 422);
        }
        if (! $cc->activo) {
            return response()->json(['ok' => true, 'data' => ['mensaje' => 'Ya estaba inactivo.']]);
        }

        DB::table('erp_centros_costo')
            ->where('id', $id)
            ->update([
                'activo' => 0,
                'eliminada_at' => now(),
                'eliminada_por' => $request->user()->id,
                'updated_at' => now(),
            ]);

        $this->audit->logEvento(
            accion: 'CC_ELIMINADO',
            modulo: 'contabilidad',
            descripcion: "CC eliminado (soft delete): {$cc->codigo} — {$cc->nombre}",
            empresaId: $empresaId,
        );

        return response()->json(['ok' => true]);
    }

    public function reactivar(Request $request, int $id): JsonResponse
    {
        if (! $this->permiso($request, 'contabilidad.centros_costo.eliminar')) {
            return $this->sinPermiso();
        }
        $empresaId = $this->empresaId($request);
        $cc = DB::table('erp_centros_costo')->where('id', $id)->where('empresa_id', $empresaId)->first();
        if (! $cc) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        if ($cc->activo) {
            return response()->json(['ok' => true, 'data' => ['mensaje' => 'Ya estaba activo.']]);
        }

        DB::table('erp_centros_costo')
            ->where('id', $id)
            ->update([
                'activo' => 1,
                'reactivada_at' => now(),
                'reactivada_por' => $request->user()->id,
                'eliminada_at' => null,
                'eliminada_por' => null,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    private function empresaId(Request $request): int
    {
        return $request->user()?->erpPerfil?->empresa_id ?? 1;
    }

    private function permiso(Request $request, string $codigo): bool
    {
        return (bool) ($request->user()?->erpPerfil?->tienePermiso($codigo) ?? false);
    }

    private function sinPermiso(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => ['code' => 'SIN_PERMISO'],
        ], 403);
    }
}
