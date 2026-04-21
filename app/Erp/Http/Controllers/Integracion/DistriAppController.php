<?php

namespace App\Erp\Http\Controllers\Integracion;

use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Services\Integracion\DistriAppBridge;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DistriAppController extends Controller
{
    public function __construct(
        private DistriAppBridge $bridge,
        private ContabilizadorFacturas $contabilizador,
    ) {}

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
