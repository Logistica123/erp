<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\MatchingContraparteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de reglas de auto-conciliación + tester (SPEC Conciliación CM-4 §6).
 *
 *   GET    /conciliacion-reglas             list paginado/filtrado
 *   POST   /conciliacion-reglas             create
 *   GET    /conciliacion-reglas/{id}        show
 *   PATCH  /conciliacion-reglas/{id}        update parcial
 *   DELETE /conciliacion-reglas/{id}        delete
 *   POST   /conciliacion-reglas/{id}/probar prueba la regla contra un mov real
 */
class ConciliacionReglasController
{
    public function __construct(
        private readonly MatchingContraparteService $matcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        $q = ConciliacionRegla::query()
            ->where('empresa_id', $empresaId)
            ->with(['cuentaContable:id,codigo,nombre', 'banco:id,codigo,nombre'])
            ->orderBy('orden_prioridad')
            ->orderBy('id');

        if ($bancoId = $request->integer('banco_id')) {
            $q->where(fn ($qq) => $qq->whereNull('banco_id')->orWhere('banco_id', $bancoId));
        }
        if ($activa = $request->input('activa')) {
            $q->where('activa', filter_var($activa, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if ($search = $request->string('q')->toString()) {
            $q->where(fn ($qq) => $qq->where('codigo', 'LIKE', "%{$search}%")
                ->orWhere('descripcion', 'LIKE', "%{$search}%")
                ->orWhere('patron_concepto', 'LIKE', "%{$search}%"));
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $regla = $this->buscar($request, $id);
        return response()->json(['data' => $regla->load(['cuentaContable', 'banco', 'auxiliar', 'centroCosto', 'diario'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $data['empresa_id'] = (int) ($request->header('X-Empresa-Id') ?: 1);
        $regla = ConciliacionRegla::create($data);
        return response()->json(['ok' => true, 'data' => $regla], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $regla = $this->buscar($request, $id);
        $data = $this->validar($request, partial: true);
        $regla->update($data);
        return response()->json(['ok' => true, 'data' => $regla->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $regla = $this->buscar($request, $id);
        $regla->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Prueba la regla contra un movimiento existente. Devuelve el resultado
     * que el MatchingContraparteService daría al aplicar todas las reglas
     * activas en orden — muy útil para que el operador verifique que su
     * regla nueva matchea como espera antes de marcarla activa.
     */
    public function probar(Request $request, int $id): JsonResponse
    {
        $regla = $this->buscar($request, $id);
        $movId = (int) $request->validate(['movimiento_id' => ['required', 'integer']])['movimiento_id'];
        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($movId);

        $resultado = $this->matcher->matchear($mov);
        $coincide = ($resultado['regla_aplicada_id'] ?? null) === $regla->id;

        return response()->json([
            'ok' => true,
            'data' => [
                'regla_id' => $regla->id,
                'movimiento_id' => $mov->id,
                'concepto' => $mov->concepto,
                'importe' => $mov->importeFirmado(),
                'estrategia_ganadora' => $resultado['estrategia'],
                'regla_aplicada_id' => $resultado['regla_aplicada_id'],
                'esta_regla_es_la_ganadora' => $coincide,
                'confianza' => $resultado['confianza_match'],
                'cuenta_contable_propuesta_id' => $resultado['cuenta_contable_propuesta_id'],
            ],
        ]);
    }

    private function buscar(Request $request, int $id): ConciliacionRegla
    {
        return ConciliacionRegla::query()
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
    }

    private function validar(Request $request, bool $partial = false): array
    {
        $req = fn (array $rules) => $partial ? array_merge(['sometimes'], $rules) : array_merge(['required'], $rules);

        return $request->validate([
            'codigo' => [...$req(['string', 'max:30'])],
            'descripcion' => [...$req(['string', 'max:200'])],
            'tipo' => [...$req([Rule::in(['CONCEPTO_REGEX', 'IMPORTE_EXACTO', 'COMBINADA'])])],
            'patron_concepto' => ['nullable', 'string', 'max:500'],
            'patron_importe_desde' => ['nullable', 'numeric'],
            'patron_importe_hasta' => ['nullable', 'numeric'],
            'cuenta_contable_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'auxiliar_id' => ['nullable', 'integer'],
            'centro_costo_id' => ['nullable', 'integer'],
            'diario_id' => ['nullable', 'integer'],
            'orden_prioridad' => ['nullable', 'integer', 'min:1'],
            'activa' => ['nullable', 'boolean'],
            'banco_id' => ['nullable', 'integer', 'exists:erp_bancos,id'],
            'cod_concepto' => ['nullable', 'string', 'max:10'],
            'signo' => ['nullable', Rule::in(['DEBITO', 'CREDITO', 'AMBOS'])],
            'confianza' => ['nullable', 'integer', 'min:0', 'max:100'],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
