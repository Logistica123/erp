<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Jobs\SyncCotizacionesBcra;
use App\Erp\Models\Cotizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cotizaciones diarias por moneda (SPEC_01 §5.1).
 *
 *   GET  /api/erp/cotizaciones?moneda=&desde=&hasta=&tipo=
 *   POST /api/erp/cotizaciones                Carga manual
 *   POST /api/erp/cotizaciones/sync-bcra       Encola SyncCotizacionesBcra
 */
class CotizacionesController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'moneda' => ['nullable', 'string'],     // código (USD, EUR)
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'tipo' => ['nullable', 'string'],
            'empresa_id' => ['nullable', 'integer'],
        ]);

        $query = Cotizacion::query()
            ->with('moneda:id,codigo,nombre')
            ->when($data['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($data['moneda'] ?? null, function ($q, $v) {
                $q->whereHas('moneda', fn ($qq) => $qq->where('codigo', $v));
            })
            ->when($data['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($data['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->orderByDesc('fecha');

        return response()->json(['ok' => true, 'data' => $query->paginate(100)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:erp_empresas,id'],
            'moneda_id' => ['required', 'integer', 'exists:erp_monedas,id'],
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in([
                'OFICIAL', 'MEP', 'CCL', 'BLUE', 'BCRA_COMPRADOR', 'BCRA_VENDEDOR', 'CUSTOM',
            ])],
            'valor_compra' => ['nullable', 'numeric', 'min:0'],
            'valor_venta' => ['nullable', 'numeric', 'min:0'],
            'valor_referencia' => ['required', 'numeric', 'min:0'],
            'fuente' => ['nullable', 'string', 'max:60'],
            'notas' => ['nullable', 'string'],
        ]);

        $cot = Cotizacion::updateOrCreate(
            [
                'empresa_id' => $data['empresa_id'],
                'moneda_id' => $data['moneda_id'],
                'fecha' => $data['fecha'],
                'tipo' => $data['tipo'],
            ],
            [
                'valor_compra' => $data['valor_compra'] ?? $data['valor_referencia'],
                'valor_venta' => $data['valor_venta'] ?? $data['valor_referencia'],
                'valor_referencia' => $data['valor_referencia'],
                'fuente' => $data['fuente'] ?? 'MANUAL',
                'notas' => $data['notas'] ?? null,
            ]
        );

        return response()->json(['ok' => true, 'data' => $cot->fresh('moneda')], 201);
    }

    public function syncBcra(Request $request): JsonResponse
    {
        $fecha = $request->input('fecha');
        SyncCotizacionesBcra::dispatch($fecha);

        return response()->json([
            'ok' => true,
            'message' => 'Sincronización encolada. Se consultará el BCRA para la fecha '.($fecha ?? 'hoy').'.',
        ], 202);
    }
}
