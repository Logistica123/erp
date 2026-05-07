<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\Diario;
use App\Erp\Models\Tesoreria\Banco;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MedioPago;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Erp\Models\Periodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogos de lectura para alimentar selects del frontend:
 * empresas, diarios, ejercicios, periodos, monedas.
 */
class CatalogosController
{
    public function empresaActual(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $empresa = Empresa::findOrFail($empresaId);

        return response()->json([
            'data' => [
                'id' => $empresa->id,
                'razon_social' => $empresa->razon_social,
                'cuit' => $empresa->cuit,
                'condicion_iva' => $empresa->condicion_iva,
                'moneda_base' => $empresa->moneda_base,
                'aplica_rt6' => (bool) $empresa->aplica_rt6,
            ],
        ]);
    }

    public function diarios(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $diarios = Diario::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'tipo', 'numerador_actual']);

        return response()->json(['data' => $diarios]);
    }

    public function ejercicios(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $ejercicios = Ejercicio::where('empresa_id', $empresaId)
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json(['data' => $ejercicios]);
    }

    public function periodos(Request $request): JsonResponse
    {
        $request->validate([
            'ejercicio_id' => ['nullable', 'integer'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $ejercicioId = $request->integer('ejercicio_id') ?: null;

        $query = Periodo::query()
            ->whereHas('ejercicio', fn ($q) => $q->where('empresa_id', $empresaId))
            ->orderBy('anio')
            ->orderBy('mes');

        if ($ejercicioId) {
            $query->where('ejercicio_id', $ejercicioId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function periodoAbierto(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $periodo = Periodo::query()
            ->whereHas('ejercicio', fn ($q) => $q->where('empresa_id', $empresaId))
            ->where('estado', 'ABIERTO')
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->first();

        if (! $periodo) {
            return response()->json(['data' => null, 'message' => 'No hay período abierto.'], 404);
        }

        return response()->json(['data' => $periodo]);
    }

    public function monedas(): JsonResponse
    {
        return response()->json([
            'data' => Moneda::where('activa', true)->orderByDesc('es_base')->orderBy('codigo')->get(),
        ]);
    }

    public function centrosCosto(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        return response()->json([
            'data' => CentroCosto::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'tipo', 'padre_id']),
        ]);
    }

    public function bancos(): JsonResponse
    {
        return response()->json([
            'data' => Banco::where('activo', true)->orderBy('codigo')->get(),
        ]);
    }

    public function cuentasBancarias(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        return response()->json([
            'data' => CuentaBancaria::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->with(['banco:id,codigo,nombre', 'moneda:id,codigo'])
                ->orderBy('codigo')
                ->get(),
        ]);
    }

    public function cajas(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        return response()->json([
            'data' => Caja::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->with('moneda:id,codigo')
                ->orderBy('codigo')
                ->get(),
        ]);
    }

    public function mediosPago(): JsonResponse
    {
        return response()->json([
            'data' => MedioPago::where('activo', true)->orderBy('codigo')->get(),
        ]);
    }

    public function auxiliares(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        $query = Auxiliar::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre');

        if ($tipo = $request->string('tipo')->toString()) {
            $query->where('tipo', $tipo);
        }

        if ($q = trim($request->string('q')->toString())) {
            $query->where(function ($sub) use ($q) {
                $sub->where('nombre', 'like', "%{$q}%")
                    ->orWhere('codigo', 'like', "{$q}%")
                    ->orWhere('cuit', 'like', "{$q}%");
            });
        }

        return response()->json([
            'data' => $query->limit(50)->get(['id', 'tipo', 'codigo', 'nombre', 'cuit', 'cuenta_contable_default_id']),
        ]);
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}
