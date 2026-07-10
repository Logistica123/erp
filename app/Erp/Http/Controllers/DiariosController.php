<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Diario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * v1.55 Bloque C — ABM de diarios contables (erp_diarios).
 *
 * El catálogo read-only de siempre sigue en CatalogosController::diarios
 * (solo activos, para selects). Este controller es el ABM del módulo Admin:
 *   GET    /api/erp/admin/diarios        Listado completo (incluye inactivos)
 *   POST   /api/erp/admin/diarios        Crea diario
 *   PATCH  /api/erp/admin/diarios/{id}   Edita nombre/descripcion/tipo/activo
 *
 * Sin DELETE: los diarios tienen numerador y asientos colgando; se
 * desactivan (activo=0) y AsientoService ya exige diario activo.
 */
class DiariosController
{
    private const TIPOS = ['MANUAL', 'SISTEMA', 'BANCO', 'VENTAS', 'COMPRAS', 'TESORERIA', 'AJUSTE', 'APERTURA', 'CIERRE'];

    public function index(Request $request): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        $diarios = Diario::where('empresa_id', $empresaId)
            ->withCount('asientos')
            ->orderBy('codigo')
            ->get();

        return response()->json(['ok' => true, 'data' => $diarios]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('erp_diarios', 'codigo')->where('empresa_id', $empresaId)],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:400'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
        ]);

        $diario = Diario::create([
            'empresa_id' => $empresaId,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'tipo' => $data['tipo'],
            'numerador_actual' => 0,
            'activo' => true,
        ]);

        return response()->json(['ok' => true, 'data' => $diario], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $diario = Diario::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:400'],
            'tipo' => ['sometimes', Rule::in(self::TIPOS)],
            'activo' => ['sometimes', 'boolean'],
        ]);

        // No se permite cambiar codigo ni numerador: el numerador es el
        // correlativo legal de asientos (RN-9).
        $diario->update($data);

        return response()->json(['ok' => true, 'data' => $diario->fresh()->loadCount('asientos')]);
    }
}
