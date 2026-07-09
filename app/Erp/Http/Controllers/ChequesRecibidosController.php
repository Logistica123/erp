<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Tesoreria\ChequeRecibidoService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChequesRecibidosController
{
    public function __construct(private readonly ChequeRecibidoService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.ver');
        $filtros = $request->validate([
            'estado' => ['nullable', 'in:EN_CARTERA,DEPOSITADO,COBRADO,RECHAZADO,VENCIDO_NO_COBRADO,DESCONTADO,ENDOSADO'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'numero' => ['nullable', 'string'],
            'solo_vencidos_sin_cobrar' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        return response()->json(['ok' => true, 'data' => $this->svc->listar($filtros)]);
    }

    public function alertas(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.ver');
        return response()->json(['ok' => true, 'data' => $this->svc->alertasVencidos()]);
    }

    public function depositar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'fecha_cobro' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $c = $this->svc->depositar($id, (int) $data['cuenta_bancaria_id'], $data['fecha_cobro'], $request->user()->id, $data['observaciones'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function descontar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'entidad' => ['nullable', 'string', 'max:150'],
            'fecha' => ['required', 'date'],
            'intereses' => ['nullable', 'numeric', 'min:0'],
            'iva' => ['nullable', 'numeric', 'min:0'],
            'comision' => ['nullable', 'numeric', 'min:0'],
            'sellado' => ['nullable', 'numeric', 'min:0'],
            'percepcion_iva' => ['nullable', 'numeric', 'min:0'],
            'percepcion_iibb' => ['nullable', 'numeric', 'min:0'],
            'otros' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $c = $this->svc->descontar($id, $data, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    /** Cheques pendientes de cobro a una fecha de corte (detalle + total). */
    public function pendientesAFecha(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.ver');
        $data = $request->validate(['fecha' => ['required', 'date']]);
        return response()->json(['ok' => true,
            'data' => $this->svc->pendientesAFecha(substr($data['fecha'], 0, 10))]);
    }

    public function facturasEndosables(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate(['proveedor_id' => ['required', 'integer']]);
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        return response()->json(['ok' => true,
            'data' => $this->svc->facturasEndosables($empresaId, (int) $data['proveedor_id'])]);
    }

    public function endosar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'proveedor_auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'fecha' => ['required', 'date'],
            'imputaciones' => ['required', 'array', 'min:1'],
            'imputaciones.*.factura_compra_id' => ['required', 'integer'],
            'imputaciones.*.importe' => ['required', 'numeric', 'min:0.01'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $c = $this->svc->endosar($id, $data, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function editar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'fecha_cobro' => ['nullable', 'date'],
            'cuenta_bancaria_id' => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $c = $this->svc->editarCobro($id, $data, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        try {
            $c = $this->svc->anularCobro($id, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function cobrar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'fecha_acreditacion' => ['required', 'date'],
            'mov_bancario_id' => ['nullable', 'integer'],
        ]);
        try {
            $c = $this->svc->cobrar($id, $data['fecha_acreditacion'], $request->user()->id, $data['mov_bancario_id'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        try {
            $c = $this->svc->rechazar($id, $data['motivo'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $c]);
    }

    public function marcarVencidos(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.cheques.gestionar');
        $n = $this->svc->marcarVencidos();
        return response()->json(['ok' => true, 'data' => ['actualizados' => $n]]);
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

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
