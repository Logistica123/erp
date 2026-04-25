<?php

namespace App\Erp\Http\Controllers\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Services\Af\AfAmortizacionService;
use App\Erp\Services\Af\AfMovimientoService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Amortizaciones + movimientos contables AF (SPEC 06 §6.2 + §6.3).
 *
 *   POST  /af/amortizaciones/generar              {periodo_anio, periodo_mes, dry_run?}
 *   GET   /af/amortizaciones                      ?anio=&mes=
 *   GET   /af/bienes/{id}/amortizaciones          (timeline)
 *
 *   POST  /af/bienes/{id}/mejora       {importe, descripcion?, factura_compra_id?}
 *   POST  /af/bienes/{id}/transferir   {cc_nuevo_id}
 *   POST  /af/bienes/{id}/revaluo      {nuevo_valor, descripcion?}
 *   POST  /af/bienes/{id}/baja         {motivo, fecha?, valor_recupero?, factura_venta_baja_id?}
 */
class AfAmortizacionesController extends Controller
{
    public function __construct(
        private readonly AfAmortizacionService $amort,
        private readonly AfMovimientoService $movs,
    ) {}

    public function generar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'periodo_anio' => ['required', 'integer', 'min:2024', 'max:2100'],
            'periodo_mes'  => ['required', 'integer', 'min:1', 'max:12'],
            'dry_run'      => ['nullable', 'boolean'],
        ]);

        try {
            $res = $this->amort->generar(
                (int) $datos['periodo_anio'],
                (int) $datos['periodo_mes'],
                $request->user(),
                (bool) ($datos['dry_run'] ?? false)
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function listar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer'],
            'mes'  => ['required', 'integer', 'min:1', 'max:12'],
        ]);
        $rows = $this->amort->listar((int) $datos['anio'], (int) $datos['mes']);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function porBien(int $bienId, Request $request): JsonResponse
    {
        $bien = AfBien::where('empresa_id', $this->empresaId($request))->findOrFail($bienId);
        $rows = $bien->hasMany(\App\Erp\Models\Af\AfAmortizacion::class, 'bien_id')
            ->orderBy('periodo_anio')->orderBy('periodo_mes')->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    // ----- Movimientos contables -----

    public function mejora(int $bienId, Request $request): JsonResponse
    {
        $bien = $this->bien($bienId, $request);
        $datos = $request->validate([
            'importe'           => ['required', 'numeric', 'min:0.01'],
            'fecha'             => ['nullable', 'date'],
            'descripcion'       => ['nullable', 'string', 'max:255'],
            'factura_compra_id' => ['nullable', 'integer'],
            'vu_extension_meses'=> ['nullable', 'integer', 'min:0', 'max:600'],
        ]);
        try {
            $res = $this->movs->mejora($bien, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res], Response::HTTP_CREATED);
    }

    public function revaluo(int $bienId, Request $request): JsonResponse
    {
        $bien = $this->bien($bienId, $request);
        $datos = $request->validate([
            'nuevo_valor' => ['required', 'numeric', 'min:0.01'],
            'fecha'       => ['nullable', 'date'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $res = $this->movs->revaluo($bien, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res], Response::HTTP_CREATED);
    }

    public function baja(int $bienId, Request $request): JsonResponse
    {
        $bien = $this->bien($bienId, $request);
        $datos = $request->validate([
            'motivo'                => ['required', 'string', 'min:3', 'max:255'],
            'fecha'                 => ['nullable', 'date'],
            'valor_recupero'        => ['nullable', 'numeric', 'min:0'],
            'factura_venta_baja_id' => ['nullable', 'integer'],
            'cuenta_recupero_id'    => ['nullable', 'integer'],
        ]);
        try {
            $res = $this->movs->baja($bien, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res], Response::HTTP_CREATED);
    }

    public function vincularAsiento(int $movimientoId, Request $request): JsonResponse
    {
        $datos = $request->validate(['asiento_id' => ['required', 'integer']]);
        $mov = \App\Erp\Models\Af\AfMovimiento::findOrFail($movimientoId);
        $mov = $this->movs->vincularAsiento($mov, (int) $datos['asiento_id']);
        return response()->json(['ok' => true, 'data' => $mov]);
    }

    private function bien(int $id, Request $request): AfBien
    {
        return AfBien::where('empresa_id', $this->empresaId($request))->findOrFail($id);
    }

    private function empresaId(Request $request): int
    {
        return (int) ($request->header('X-Empresa-Id') ?: 1);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
