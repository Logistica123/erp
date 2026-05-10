<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\CuentaContable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * POST /api/erp/cuentas — crear cuenta nueva (v1.15 Sprint L · O-PC-3 fix).
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:30'],
            'nombre' => ['required', 'string', 'max:200'],
            'codigo_padre_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'tipo' => ['required', 'string', 'in:A,P,PN,R+,R-,O'],
            'rubro_ec' => ['nullable', 'string', 'max:60'],
            'imputable' => ['required', 'boolean'],
            'moneda' => ['nullable', 'string', 'in:ARS,USD'],
            'admite_cc' => ['nullable', 'boolean'],
            'admite_auxiliar' => ['nullable', 'boolean'],
            'tipo_auxiliar' => ['nullable', 'string', 'max:30'],
            'etiqueta_cierre' => ['nullable', 'string', 'max:60'],
            'saldo_normal' => ['nullable', 'string', 'in:DEUDOR,ACREEDOR'],
            'regularizadora' => ['nullable', 'boolean'],
        ]);

        // Validar unicidad por (empresa_id, codigo)
        $existe = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)
            ->where('codigo', $data['codigo'])
            ->exists();
        if ($existe) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CODIGO_DUPLICADO', 'message' => "Ya existe una cuenta con código {$data['codigo']}."],
            ], 422);
        }

        // Calcular nivel desde el padre.
        $nivel = 1;
        if (! empty($data['codigo_padre_id'])) {
            $padreNivel = DB::table('erp_cuentas_contables')->where('id', $data['codigo_padre_id'])->value('nivel');
            $nivel = (int) $padreNivel + 1;
            if ($nivel > 4) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'NIVEL_MAX', 'message' => 'No se permiten cuentas más allá del nivel 4.'],
                ], 422);
            }
        }

        $id = DB::table('erp_cuentas_contables')->insertGetId([
            'empresa_id' => $empresaId,
            'codigo' => $data['codigo'],
            'codigo_padre_id' => $data['codigo_padre_id'] ?? null,
            'nivel' => $nivel,
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'rubro_ec' => $data['rubro_ec'] ?? null,
            'imputable' => $data['imputable'],
            'moneda' => $data['moneda'] ?? null,
            'admite_cc' => $data['admite_cc'] ?? false,
            'admite_auxiliar' => $data['admite_auxiliar'] ?? false,
            'tipo_auxiliar' => $data['tipo_auxiliar'] ?? null,
            'etiqueta_cierre' => $data['etiqueta_cierre'] ?? null,
            'saldo_normal' => $data['saldo_normal'] ?? ($data['tipo'] === 'A' || $data['tipo'] === 'R-' ? 'DEUDOR' : 'ACREEDOR'),
            'regularizadora' => $data['regularizadora'] ?? false,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->present(CuentaContable::find($id)),
        ], 201);
    }

    /**
     * DELETE /api/erp/cuentas/{id} — soft delete (v1.15 Sprint L · O-PC-2 fix).
     *
     * Bloqueada si:
     *   (a) tiene movimientos contables en erp_movimientos_asiento;
     *   (b) tiene sub-cuentas con activo=1;
     *   (c) está referenciada como cuenta default en otras tablas.
     *
     * En cualquiera de los 3 casos: 422 con motivo + lista de referencias para
     * que el frontend muestre modal explicativo. El query param ?force=1 permite
     * desactivar igualmente (solo cambia activo=0 sin tocar las referencias).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $perfil = $user?->erpPerfil;
        if (! $perfil?->tienePermiso('contabilidad.cuentas.eliminar')) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'SIN_PERMISO', 'message' => 'Necesitás el permiso contabilidad.cuentas.eliminar.'],
            ], 403);
        }

        $empresaId = $this->empresaIdFromRequest($request);
        $cuenta = CuentaContable::where('empresa_id', $empresaId)->find($id);
        if (! $cuenta) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA']], 404);
        }
        if (! $cuenta->activo) {
            return response()->json(['ok' => true, 'data' => ['mensaje' => 'Ya estaba desactivada.']]);
        }

        $bloqueos = $this->bloqueosEliminar($id, $empresaId);
        $force = $request->boolean('force');

        if (! empty($bloqueos) && ! $force) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'CUENTA_CON_REFERENCIAS',
                    'message' => 'La cuenta tiene referencias activas. Mirá detalle para decidir.',
                    'bloqueos' => $bloqueos,
                    'sugerencia' => 'Podés desactivarla igualmente con ?force=1 (queda invisible en formularios pero conserva el histórico).',
                ],
            ], 422);
        }

        $cuenta->update([
            'activo' => 0,
            'eliminada_at' => now(),
            'eliminada_por' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $cuenta->id,
                'codigo' => $cuenta->codigo,
                'desactivada_con_referencias' => $force && ! empty($bloqueos),
                'bloqueos_ignorados' => $force ? $bloqueos : [],
            ],
        ]);
    }

    /**
     * GET /api/erp/cuentas/exportar — descarga CSV del plan (v1.15 · O-PC-4 fix).
     */
    public function exportar(Request $request): StreamedResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $q = CuentaContable::where('empresa_id', $empresaId)->orderBy('codigo');
        if (! $request->boolean('incluir_inactivas')) {
            $q->where('activo', 1);
        }
        $cuentas = $q->get();

        $filename = 'plan_de_cuentas_'.now()->format('Ymd_His').'.csv';

        return response()->stream(function () use ($cuentas) {
            $out = fopen('php://output', 'w');
            // BOM para Excel.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Código', 'Nombre', 'Nivel', 'Tipo', 'Rubro EC',
                'Imputable', 'Moneda', 'Admite CC', 'Admite Auxiliar',
                'Tipo Auxiliar', 'Etiqueta Cierre', 'Saldo Normal',
                'Regularizadora', 'Activo',
            ], ';');
            foreach ($cuentas as $c) {
                fputcsv($out, [
                    $c->codigo, $c->nombre, $c->nivel, $c->tipo, $c->rubro_ec,
                    $c->imputable ? 'SI' : 'NO',
                    $c->moneda, $c->admite_cc ? 'SI' : 'NO',
                    $c->admite_auxiliar ? 'SI' : 'NO',
                    $c->tipo_auxiliar, $c->etiqueta_cierre,
                    $c->saldo_normal,
                    $c->regularizadora ? 'SI' : 'NO',
                    $c->activo ? 'SI' : 'NO',
                ], ';');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Devuelve el array de bloqueos actuales para una cuenta (las referencias
     * activas que impiden el soft delete sin force).
     *
     * @return array<int, array{tipo:string, count:int, descripcion:string}>
     */
    private function bloqueosEliminar(int $cuentaId, int $empresaId): array
    {
        $bloqueos = [];

        $movs = DB::table('erp_movimientos_asiento')->where('cuenta_id', $cuentaId)->count();
        if ($movs > 0) {
            $bloqueos[] = [
                'tipo' => 'MOVIMIENTOS',
                'count' => $movs,
                'descripcion' => "{$movs} movimiento(s) contable(s) registrados sobre esta cuenta.",
            ];
        }

        $hijos = DB::table('erp_cuentas_contables')
            ->where('codigo_padre_id', $cuentaId)
            ->where('activo', 1)
            ->count();
        if ($hijos > 0) {
            $bloqueos[] = [
                'tipo' => 'SUBCUENTAS',
                'count' => $hijos,
                'descripcion' => "{$hijos} sub-cuenta(s) activa(s) cuelgan de esta cuenta.",
            ];
        }

        $auxRefs = DB::table('erp_auxiliares')
            ->where('cuenta_contable_default_id', $cuentaId)
            ->where('activo', 1)
            ->count();
        if ($auxRefs > 0) {
            $bloqueos[] = [
                'tipo' => 'AUXILIARES_DEFAULT',
                'count' => $auxRefs,
                'descripcion' => "{$auxRefs} auxiliar(es) usan esta cuenta como default.",
            ];
        }

        if ($this->tableExists('erp_conciliacion_reglas')) {
            $reglasRefs = DB::table('erp_conciliacion_reglas')
                ->where('cuenta_contable_id', $cuentaId)
                ->where('empresa_id', $empresaId)
                ->count();
            if ($reglasRefs > 0) {
                $bloqueos[] = [
                    'tipo' => 'CONCILIACION_REGLAS',
                    'count' => $reglasRefs,
                    'descripcion' => "{$reglasRefs} regla(s) de conciliación bancaria apuntan a esta cuenta.",
                ];
            }
        }

        return $bloqueos;
    }

    private function tableExists(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$table]
        );
    }
}
