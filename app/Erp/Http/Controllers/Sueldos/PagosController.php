<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Pago;
use App\Erp\Services\Sueldos\PagosSueldosService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pagos de sueldos en 3 modalidades + asiento de devengo (SPEC 08 §5.6).
 *
 *   POST  /sueldos/liquidaciones/{id}/contabilizar         devengo (asiento agregado)
 *   POST  /sueldos/liquidaciones/{id}/pagar/formal         transferencias FORMAL
 *   POST  /sueldos/liquidaciones/{id}/pagar/efectivo       efectivo (con receptores)
 *   POST  /sueldos/liquidaciones/{id}/pagar/mt             transferencias MT contra FC
 *   GET   /sueldos/liquidaciones/{id}/pagos                lista pagos de la liq
 *   GET   /sueldos/pagos/{id}                              detalle pago
 */
class PagosController extends Controller
{
    public function __construct(private readonly PagosSueldosService $service) {}

    public function contabilizar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.aprobar');
        $liq = Liquidacion::findOrFail($id);
        try {
            $asiento = $this->service->contabilizarDevengo($liq, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['asiento_id' => $asiento->id, 'numero' => $asiento->numero]]);
    }

    public function pagarFormal(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.pagos.ejecutar.formal');
        $liq = Liquidacion::findOrFail($id);
        $datos = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'fecha'              => ['required', 'date'],
        ]);
        try {
            $res = $this->service->pagarFormal($liq, (int) $datos['cuenta_bancaria_id'], $datos['fecha'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function pagarEfectivo(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.pagos.ejecutar.efectivo');
        $liq = Liquidacion::findOrFail($id);
        $datos = $request->validate([
            'caja_id'                 => ['required', 'integer', 'exists:erp_cajas,id'],
            'fecha'                   => ['required', 'date'],
            'receptores'              => ['required', 'array', 'min:1'],
            'receptores.*.empleado_id'  => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'receptores.*.recibido_por' => ['required', 'string', 'max:120'],
            'receptores.*.dni_recibio'  => ['required', 'string', 'max:15'],
        ]);
        try {
            $res = $this->service->pagarEfectivo($liq, (int) $datos['caja_id'], $datos['fecha'], $datos['receptores'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function pagarMt(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.pagos.ejecutar.mt');
        $liq = Liquidacion::findOrFail($id);
        $datos = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'fecha'              => ['required', 'date'],
            'facturas'                       => ['required', 'array', 'min:1'],
            'facturas.*.empleado_id'         => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'facturas.*.factura_compra_id'   => ['required', 'integer', 'exists:erp_facturas_compra,id'],
        ]);
        try {
            $res = $this->service->pagarMt($liq, (int) $datos['cuenta_bancaria_id'], $datos['fecha'], $datos['facturas'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function listarPorLiquidacion(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $liq = Liquidacion::findOrFail($id);

        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        $q = Pago::with(['empleado:id,legajo,apellido,nombre', 'ordenPago:id,numero,estado', 'asiento:id,numero,fecha'])
            ->where('liquidacion_id', $liq->id)
            ->orderBy('empleado_id')->orderBy('componente');
        if (! $verEfectivos) {
            $q->where('componente', '!=', 'EFECTIVO');
        }
        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $pago = Pago::with(['empleado:id,legajo,apellido,nombre,cuil', 'liquidacion:id,periodo,tipo,estado', 'ordenPago:id,numero,estado,fecha_pago', 'asiento:id,numero,fecha'])
            ->findOrFail($id);

        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;
        if ($pago->componente === 'EFECTIVO' && ! $verEfectivos) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO_COMPONENTE_EFECTIVO']], 403);
        }

        return response()->json(['ok' => true, 'data' => $pago]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
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
