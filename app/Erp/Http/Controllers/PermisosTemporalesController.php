<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * v1.29 — ABM de permisos temporales (concesión por usuario, no por rol).
 *
 * Caso de uso: Sebastián (super_admin) otorga un permiso sensible a un user
 * específico por X horas para que pueda ejecutar una acción puntual.
 * Vencido el tiempo o revocado, el permiso desaparece automáticamente.
 *
 * Endpoints:
 *   GET    /api/erp/admin/permisos-temporales       — listado (activos por default)
 *   POST   /api/erp/admin/permisos-temporales       — otorgar
 *   DELETE /api/erp/admin/permisos-temporales/{id}  — revocar
 *
 * Solo super_admin puede acceder.
 */
class PermisosTemporalesController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'super_admin');

        $q = DB::table('erp_permisos_temporales as pt')
            ->join('users as u', 'u.id', '=', 'pt.user_id')
            ->join('users as og', 'og.id', '=', 'pt.otorgado_por_user_id')
            ->select(
                'pt.id', 'pt.user_id', 'u.name as user_name', 'u.email as user_email',
                'pt.permiso_codigo',
                'pt.otorgado_por_user_id', 'og.name as otorgado_por_name',
                'pt.motivo', 'pt.otorgado_at', 'pt.expira_at', 'pt.usado_at', 'pt.revocado_at',
            )
            ->orderByDesc('pt.otorgado_at')
            ->limit(200);

        $estado = $request->query('estado', 'activos');
        if ($estado === 'activos') {
            $q->where('pt.expira_at', '>', now())->whereNull('pt.revocado_at');
        } elseif ($estado === 'vencidos') {
            $q->where(fn ($w) => $w->where('pt.expira_at', '<=', now())->orWhereNotNull('pt.revocado_at'));
        }

        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'super_admin');

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permiso_codigo' => ['required', 'string', 'exists:erp_permisos,codigo'],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
            'duracion_horas' => ['required', 'integer', 'min:1', 'max:72'],
        ]);

        $expiraAt = now()->addHours((int) $data['duracion_horas']);
        $id = DB::table('erp_permisos_temporales')->insertGetId([
            'user_id' => (int) $data['user_id'],
            'permiso_codigo' => $data['permiso_codigo'],
            'otorgado_por_user_id' => $request->user()->id,
            'motivo' => $data['motivo'],
            'otorgado_at' => now(),
            'expira_at' => $expiraAt,
        ]);

        $this->audit->logEvento(
            accion: 'PERMISO_TEMPORAL_OTORGADO',
            modulo: 'admin',
            descripcion: sprintf(
                'Permiso %s otorgado a user_id=%d por %dh (motivo: %s)',
                $data['permiso_codigo'], $data['user_id'],
                $data['duracion_horas'], $data['motivo'],
            ),
            empresaId: 1,
        );

        return response()->json(['ok' => true, 'data' => [
            'id' => $id, 'expira_at' => $expiraAt->toIso8601String(),
        ]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'super_admin');

        $pt = DB::table('erp_permisos_temporales')->where('id', $id)->first();
        if (! $pt) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        if ($pt->revocado_at !== null) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'YA_REVOCADO',
                'message' => 'Este permiso temporal ya fue revocado el '.$pt->revocado_at,
            ]], 409);
        }

        DB::table('erp_permisos_temporales')->where('id', $id)->update([
            'revocado_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'PERMISO_TEMPORAL_REVOCADO',
            modulo: 'admin',
            descripcion: sprintf('Permiso %s revocado para user_id=%d (id=%d)',
                $pt->permiso_codigo, $pt->user_id, $id),
            empresaId: 1,
        );

        return response()->json(null, 204);
    }

    private function mustHave(Request $request, string $rolCodigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
            ]], 403));
        }
        $tieneRol = $perfil->roles()->where('codigo', $rolCodigo)->exists();
        if (! $tieneRol) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Solo {$rolCodigo} puede gestionar permisos temporales.",
            ]], 403));
        }
    }
}
