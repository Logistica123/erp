<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\CuentaContable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CuentasContablesController
{
    /**
     * GET /api/erp/cuentas
     *
     * Query params:
     *   ?tree=true        → devuelve jerarquía anidada (root → hijos → ...)
     *   ?imputable=true   → solo cuentas hoja (imputables)
     *   ?moneda=ARS|USD   → filtra por moneda
     *   ?q=...            → busca por código o nombre
     *   ?activo=true|false (default true)
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        $query = CuentaContable::query()
            ->where('empresa_id', $empresaId)
            ->orderBy('codigo');

        if ($request->boolean('activo', true)) {
            $query->where('activo', true);
        }

        if ($request->boolean('imputable')) {
            $query->where('imputable', true);
        }

        if ($moneda = $request->string('moneda')->toString()) {
            $query->where('moneda', $moneda);
        }

        if ($q = trim($request->string('q')->toString())) {
            $query->where(function ($sub) use ($q) {
                $sub->where('codigo', 'like', "{$q}%")
                    ->orWhere('nombre', 'like', "%{$q}%");
            });
        }

        $cuentas = $query->get();

        if ($request->boolean('tree')) {
            return response()->json([
                'data' => $this->buildTree($cuentas->all()),
                'meta' => [
                    'total' => $cuentas->count(),
                    'imputables' => $cuentas->where('imputable', true)->count(),
                ],
            ]);
        }

        return response()->json([
            'data' => $cuentas->map(fn ($c) => $this->present($c))->all(),
            'meta' => [
                'total' => $cuentas->count(),
                'imputables' => $cuentas->where('imputable', true)->count(),
            ],
        ]);
    }

    /**
     * GET /api/erp/cuentas/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        $cuenta = CuentaContable::where('empresa_id', $empresaId)
            ->with(['padre:id,codigo,nombre', 'hijos:id,codigo_padre_id,codigo,nombre,imputable'])
            ->findOrFail($id);

        return response()->json(['data' => $this->present($cuenta, withRels: true)]);
    }

    /**
     * Convierte una lista plana de cuentas en un árbol usando codigo_padre_id.
     *
     * @param  array<int, CuentaContable>  $cuentas
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $cuentas): array
    {
        /** @var array<int, array<string, mixed>> $map */
        $map = [];
        foreach ($cuentas as $c) {
            $map[$c->id] = $this->present($c) + ['hijos' => []];
        }

        $roots = [];
        foreach ($cuentas as $c) {
            if ($c->codigo_padre_id && isset($map[$c->codigo_padre_id])) {
                $map[$c->codigo_padre_id]['hijos'][] = &$map[$c->id];
            } else {
                $roots[] = &$map[$c->id];
            }
        }

        return $roots;
    }

    /**
     * Serialización estándar de una cuenta.
     */
    private function present(CuentaContable $c, bool $withRels = false): array
    {
        $base = [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'codigo_padre_id' => $c->codigo_padre_id,
            'nivel' => $c->nivel,
            'nombre' => $c->nombre,
            'tipo' => $c->tipo,
            'rubro_ec' => $c->rubro_ec,
            'imputable' => $c->imputable,
            'moneda' => $c->moneda,
            'admite_cc' => $c->admite_cc,
            'admite_auxiliar' => $c->admite_auxiliar,
            'tipo_auxiliar' => $c->tipo_auxiliar,
            'etiqueta_cierre' => $c->etiqueta_cierre,
            'saldo_normal' => $c->saldo_normal,
            'regularizadora' => $c->regularizadora,
            'activo' => $c->activo,
        ];

        if ($withRels) {
            $base['padre'] = $c->padre ? [
                'id' => $c->padre->id,
                'codigo' => $c->padre->codigo,
                'nombre' => $c->padre->nombre,
            ] : null;
            $base['hijos'] = $c->hijos->map(fn ($h) => [
                'id' => $h->id,
                'codigo' => $h->codigo,
                'nombre' => $h->nombre,
                'imputable' => (bool) $h->imputable,
            ])->all();
        }

        return $base;
    }

    private function empresaIdFromRequest(Request $request): int
    {
        // MVP: una sola empresa. Más adelante: resolver desde el perfil del usuario o header.
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}
