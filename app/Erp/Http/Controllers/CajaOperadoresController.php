<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 — ABM de operadores autorizados de cada caja (D-42-2).
 *
 *   GET    /api/erp/caja/operadores?caja_id=N
 *   POST   /api/erp/caja/operadores                  (alta)
 *   DELETE /api/erp/caja/operadores/{id}             (baja con motivo)
 *
 * Permiso: tesoreria.caja.operadores.abm — solo super_admin por default.
 */
class CajaOperadoresController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Lookup simple de usuarios para el dropdown de alta operador.
     * GET /api/erp/users-lookup?q=&limit=
     */
    public function usersLookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min(200, (int) $request->query('limit', 100)));
        $rows = DB::table('users')
            ->when($q !== '', fn ($qb) => $qb->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'email']);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $cajaId = (int) $request->query('caja_id', 0);
        $q = DB::table('erp_cajas_operadores as op')
            ->join('users as u', 'u.id', '=', 'op.user_id')
            ->join('erp_cajas as c', 'c.id', '=', 'op.caja_id')
            ->select([
                'op.id', 'op.caja_id', 'c.codigo as caja_codigo',
                'op.user_id', 'u.name as user_name', 'u.email as user_email',
                'op.fecha_alta', 'op.fecha_baja',
                'op.motivo_alta', 'op.motivo_baja',
                'op.autorizado_por_user_id', 'op.created_at',
            ])
            ->orderByDesc('op.fecha_alta');
        if ($cajaId) $q->where('op.caja_id', $cajaId);
        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.caja.operadores.abm');
        $data = $request->validate([
            'caja_id' => ['required', 'integer', 'exists:erp_cajas,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'motivo_alta' => ['nullable', 'string', 'max:200'],
        ]);

        // Idempotente: si existe alta activa, no duplicar.
        $existe = DB::table('erp_cajas_operadores')
            ->where('caja_id', $data['caja_id'])
            ->where('user_id', $data['user_id'])
            ->whereNull('fecha_baja')
            ->exists();
        if ($existe) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'OPERADOR_YA_AUTORIZADO',
                'message' => 'Ese usuario ya está autorizado en esa caja.',
            ]], 409);
        }

        $id = DB::table('erp_cajas_operadores')->insertGetId([
            'caja_id' => $data['caja_id'],
            'user_id' => $data['user_id'],
            'fecha_alta' => today(),
            'motivo_alta' => $data['motivo_alta'] ?? null,
            'autorizado_por_user_id' => $request->user()->id,
            'created_at' => now(),
        ]);
        $this->audit->logEvento(
            accion: 'CAJA_OPERADOR_ALTA',
            modulo: 'tesoreria',
            descripcion: sprintf('Alta operador caja: caja_id=%d user_id=%d (autorizó user %d). %s',
                $data['caja_id'], $data['user_id'], $request->user()->id, $data['motivo_alta'] ?? ''),
            empresaId: 1,
        );
        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.caja.operadores.abm');
        $data = $request->validate([
            'motivo_baja' => ['required', 'string', 'min:5', 'max:200'],
        ]);
        $row = DB::table('erp_cajas_operadores')->where('id', $id)->first();
        if (! $row) abort(404);
        if ($row->fecha_baja) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'OPERADOR_YA_DADO_DE_BAJA',
                'message' => 'Ese operador ya está dado de baja.',
            ]], 409);
        }
        DB::table('erp_cajas_operadores')->where('id', $id)->update([
            'fecha_baja' => today(),
            'motivo_baja' => $data['motivo_baja'],
        ]);
        $this->audit->logEvento(
            accion: 'CAJA_OPERADOR_BAJA',
            modulo: 'tesoreria',
            descripcion: sprintf('Baja operador caja_id=%d user_id=%d (autorizó user %d). Motivo: %s',
                $row->caja_id, $row->user_id, $request->user()->id, $data['motivo_baja']),
            empresaId: 1,
        );
        return response()->json(['ok' => true]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }
}
