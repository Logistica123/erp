<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\AliasContraparte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD del cache de alias de contraparte (SPEC Conciliación CM-4 §4.4).
 *
 *   GET    /alias-contraparte         list paginado/filtrado
 *   POST   /alias-contraparte         create (asignación manual nueva)
 *   PATCH  /alias-contraparte/{id}    update
 *   DELETE /alias-contraparte/{id}    soft delete (desactivación)
 */
class AliasContraparteController
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        $q = AliasContraparte::query()
            ->where('empresa_id', $empresaId)
            ->with([
                'banco:id,codigo,nombre',
                'cuentaContable:id,codigo,nombre',
                'asignadoPor:id,name',
            ])
            ->orderByDesc('asignado_at');

        if ($bancoId = $request->integer('banco_id')) {
            $q->where(fn ($qq) => $qq->whereNull('banco_id')->orWhere('banco_id', $bancoId));
        }
        if ($search = $request->string('q')->toString()) {
            $q->where('alias_normalizado', 'LIKE', '%'.AliasContraparte::normalizar($search).'%');
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'banco_id' => ['nullable', 'integer', 'exists:erp_bancos,id'],
            'alias' => ['required', 'string', 'max:200'],
            'persona_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'cuenta_contable_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'confianza' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if (! ($data['persona_id'] ?? null)
            && ! ($data['cliente_id'] ?? null)
            && ! ($data['cuenta_contable_id'] ?? null)) {
            return response()->json([
                'error' => ['code' => 'CONTRAPARTE_REQUERIDA',
                    'message' => 'Debe asignar al menos persona_id, cliente_id o cuenta_contable_id'],
            ], 422);
        }

        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $aliasNorm = AliasContraparte::normalizar($data['alias']);

        $alias = AliasContraparte::updateOrCreate(
            [
                'empresa_id' => $empresaId,
                'banco_id' => $data['banco_id'] ?? null,
                'alias_normalizado' => $aliasNorm,
            ],
            [
                'persona_id' => $data['persona_id'] ?? null,
                'cliente_id' => $data['cliente_id'] ?? null,
                'cuenta_contable_id' => $data['cuenta_contable_id'] ?? null,
                'confianza' => (int) ($data['confianza'] ?? 100),
                'asignado_por' => $request->user()->id,
                'asignado_at' => now(),
            ]
        );

        return response()->json(['ok' => true, 'data' => $alias->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $alias = AliasContraparte::query()
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);

        $data = $request->validate([
            'persona_id' => ['nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'cuenta_contable_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'confianza' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $alias->update($data);
        return response()->json(['ok' => true, 'data' => $alias->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $alias = AliasContraparte::query()
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
        $alias->delete();
        return response()->json(['ok' => true]);
    }
}
