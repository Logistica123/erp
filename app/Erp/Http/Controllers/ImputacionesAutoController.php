<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Conciliacion\ImputacionAutoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 — Imputaciones automáticas (MATCH_AUTO / CONFIRMADO / REVERTIDO).
 *
 *   GET    /api/erp/conciliacion/imputaciones-pendientes
 *   PATCH  /api/erp/conciliacion/{mov}/modificar
 *   POST   /api/erp/conciliacion/{mov}/confirmar
 *   POST   /api/erp/conciliacion/{mov}/revertir
 *   GET    /api/erp/conciliacion/{mov}/audit
 */
class ImputacionesAutoController
{
    public function __construct(private readonly ImputacionAutoService $svc) {}

    public function pendientes(Request $request): JsonResponse
    {
        $this->mustHave($request, 'extractos.imputaciones.confirmar');
        $estado = $request->query('estado', 'MATCH_AUTO');
        $q = DB::table('erp_movimientos_bancarios as m')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'm.auxiliar_resuelto_id')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->select([
                'm.id', 'm.fecha', 'm.concepto', 'm.debito', 'm.credito',
                'm.estado', 'm.cuit_extractado', 'm.imputacion_confianza',
                'm.factura_imputada_id', 'm.factura_imputada_tipo', 'm.auxiliar_resuelto_id',
                'a.nombre as auxiliar_nombre', 'a.cuit as auxiliar_cuit',
                'cb.nombre as cuenta_nombre',
            ])
            ->whereIn('m.estado', ['MATCH_AUTO', 'CONFIRMADO', 'REVERTIDO'])
            ->orderByDesc('m.fecha')->orderByDesc('m.id');
        if ($estado && $estado !== 'TODOS') $q->where('m.estado', $estado);
        if ($cb = $request->query('cuenta_bancaria_id')) $q->where('m.cuenta_bancaria_id', (int) $cb);
        return response()->json(['ok' => true, 'data' => $q->paginate(100)]);
    }

    public function modificar(Request $request, int $mov): JsonResponse
    {
        $this->mustHave($request, 'extractos.imputaciones.modificar');
        $data = $request->validate([
            'factura_id' => ['nullable', 'integer'],
            'factura_tipo' => ['nullable', 'in:VENTA,COMPRA'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $m = MovimientoBancario::findOrFail($mov);
        try {
            $m = $this->svc->modificar($m, $data['factura_id'] ?? null, $data['factura_tipo'] ?? null, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $m]);
    }

    public function confirmar(Request $request, int $mov): JsonResponse
    {
        $this->mustHave($request, 'extractos.imputaciones.confirmar');
        $m = MovimientoBancario::findOrFail($mov);
        try {
            $m = $this->svc->confirmar($m, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $m]);
    }

    public function revertir(Request $request, int $mov): JsonResponse
    {
        $m = MovimientoBancario::findOrFail($mov);
        // Revertir CONFIRMADO requiere permiso extra (control 4 ojos).
        $permiso = $m->estado === 'CONFIRMADO'
            ? 'extractos.imputaciones.revertir_confirmada'
            : 'extractos.imputaciones.revertir';
        $this->mustHave($request, $permiso);
        $data = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        try {
            $m = $this->svc->revertir($m, $data['motivo'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $m]);
    }

    public function audit(Request $request, int $mov): JsonResponse
    {
        $this->mustHave($request, 'extractos.imputaciones.confirmar');
        $rows = DB::table('erp_extractos_imputaciones_audit as au')
            ->leftJoin('users as u', 'u.id', '=', 'au.user_id')
            ->where('au.movimiento_id', $mov)
            ->orderByDesc('au.created_at')
            ->select(['au.*', 'u.name as user_nombre'])
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
