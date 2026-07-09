<?php

namespace App\Erp\Http\Controllers\Integracion;

use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Services\Integracion\DistriAppBridge;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistriAppController extends Controller
{
    public function __construct(
        private DistriAppBridge $bridge,
        private ContabilizadorFacturas $contabilizador,
    ) {}

    /**
     * Lista clientes de la plataforma para completarlos (buscador por nombre de
     * fantasía / código / documento). Muestra estado fiscal actual + vínculo ERP.
     */
    public function clientesPlataforma(Request $request): JsonResponse
    {
        $this->mustHave($request, 'integracion.clientes.completar');
        $termino = trim((string) $request->query('q', ''));
        return response()->json(['ok' => true, 'data' => $this->bridge->clientesPlataforma($termino, 50)]);
    }

    /** Catálogo de condición IVA del ERP para el dropdown del completador. */
    public function condicionesIva(Request $request): JsonResponse
    {
        $this->mustHave($request, 'integracion.clientes.completar');
        $data = DB::table('erp_condiciones_iva')->where('activo', 1)
            ->orderBy('id')->get(['id', 'codigo_interno', 'nombre']);
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** Completa un cliente de la plataforma con los datos fiscales reales. */
    public function completarCliente(Request $request, int $clienteId): JsonResponse
    {
        $this->mustHave($request, 'integracion.clientes.completar');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $data = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'cuit' => ['nullable', 'string', 'max:13'],
            'condicion_iva_id' => ['nullable', 'integer'],
            'domicilio_calle' => ['nullable', 'string', 'max:255'],
            'domicilio_nro' => ['nullable', 'string', 'max:20'],
            'domicilio_piso' => ['nullable', 'string', 'max:20'],
            'domicilio_depto' => ['nullable', 'string', 'max:20'],
            'localidad' => ['nullable', 'string', 'max:255'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'cod_postal' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $res = $this->bridge->completarCliente($clienteId, $data, $empresaId, $request->user()?->id);
            return response()->json(['ok' => true, 'data' => $res]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }

    public function clientes(): JsonResponse
    {
        return response()->json(['data' => $this->bridge->clientes()]);
    }

    public function distribuidores(): JsonResponse
    {
        return response()->json(['data' => $this->bridge->distribuidores()]);
    }

    public function syncClientes(): JsonResponse
    {
        $result = $this->bridge->syncClientes(empresaId: 1);
        return response()->json(['message' => 'Sincronización clientes OK', ...$result]);
    }

    public function syncDistribuidores(): JsonResponse
    {
        $result = $this->bridge->syncDistribuidores(empresaId: 1);
        return response()->json(['message' => 'Sincronización distribuidores OK', ...$result]);
    }

    public function facturas(): JsonResponse
    {
        return response()->json(['data' => $this->bridge->facturasDistriapp()]);
    }

    public function syncFacturas(): JsonResponse
    {
        $result = $this->bridge->syncFacturas(empresaId: 1);
        return response()->json(['message' => 'Sincronización facturas OK', ...$result]);
    }

    public function liquidacionesDistrib(): JsonResponse
    {
        return response()->json(['data' => $this->bridge->liquidacionesDistrib()]);
    }

    public function contabilizarFacturas(): JsonResponse
    {
        $user = request()->user();
        $usuarioId = $user ? $user->id : 1;
        $result = $this->contabilizador->contabilizarPendientes(empresaId: 1, usuarioId: $usuarioId);
        return response()->json(['message' => 'Contabilización OK', ...$result]);
    }
}
