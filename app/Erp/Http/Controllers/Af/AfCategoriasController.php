<?php

namespace App\Erp\Http\Controllers\Af;

use App\Erp\Models\Af\AfCategoria;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Categorías AF (SPEC 06 §6.1).
 *
 *   GET    /af/categorias         — listado
 *   POST   /af/categorias         — alta
 *   GET    /af/categorias/{id}    — detalle
 *   PUT    /af/categorias/{id}    — editar
 *   DELETE /af/categorias/{id}    — soft delete (activa=false)
 */
class AfCategoriasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AfCategoria::query()
            ->when($request->boolean('solo_activas', true), fn ($q) => $q->where('activa', 1))
            ->orderBy('codigo');
        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function show(int $id): JsonResponse
    {
        $cat = AfCategoria::with([
            'cuentaBien', 'cuentaAmortAcum', 'cuentaAmortGasto',
            'cuentaResultPos', 'cuentaResultNeg',
        ])->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $cat]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        $cat = AfCategoria::create($datos);
        return response()->json(['ok' => true, 'data' => $cat], Response::HTTP_CREATED);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $cat = AfCategoria::findOrFail($id);
        $datos = $this->validar($request, $id);
        $cat->update($datos);
        return response()->json(['ok' => true, 'data' => $cat->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $cat = AfCategoria::findOrFail($id);
        $cat->update(['activa' => false]);
        return response()->json(['ok' => true, 'data' => $cat->fresh()]);
    }

    private function validar(Request $request, ?int $idActualizar = null): array
    {
        $reglas = [
            'codigo'                       => ['required', 'string', 'max:20'],
            'nombre'                       => ['required', 'string', 'max:100'],
            'descripcion'                  => ['nullable', 'string'],
            'vida_util_contable_meses'     => ['required', 'integer', 'min:1', 'max:1200'],
            'vida_util_fiscal_meses'       => ['required', 'integer', 'min:1', 'max:1200'],
            'valor_residual_pct'           => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'metodo_amortizacion'          => ['nullable', 'in:LINEAL,UNIDADES'],
            'cuenta_bien_id'               => ['required', 'integer'],
            'cuenta_amort_acum_id'         => ['required', 'integer'],
            'cuenta_amort_ejercicio_id'    => ['required', 'integer'],
            'cuenta_resultado_baja_pos_id' => ['required', 'integer'],
            'cuenta_resultado_baja_neg_id' => ['required', 'integer'],
            'umbral_baja_cuantia'          => ['nullable', 'numeric', 'min:0'],
            'activa'                       => ['nullable', 'boolean'],
        ];
        return $request->validate($reglas);
    }
}
