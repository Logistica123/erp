<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Auxiliar;
use App\Erp\Services\Integracion\DistriAppBridge;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administración de auxiliares (SPEC_01 §5.2, §9).
 *   GET    /api/erp/auxiliares/buscar?tipo=&q=      Autocomplete desde DistriApp o locales
 *   POST   /api/erp/auxiliares                      Crea o vincula desde personas/clientes
 *   GET    /api/erp/auxiliares/{id}/saldo           Saldo al período
 */
class AuxiliaresController
{
    public function __construct(private readonly DistriAppBridge $bridge) {}

    public function buscar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(['Cliente', 'Distribuidor'])],
            'q' => ['required', 'string', 'min:2'],
            'fuente' => ['nullable', Rule::in(['locales', 'distriapp', 'ambas'])],
        ]);

        $fuente = $data['fuente'] ?? 'ambas';
        $q = $data['q'];

        $locales = collect();
        if ($fuente !== 'distriapp') {
            $locales = Auxiliar::where('tipo', $data['tipo'])
                ->where('activo', true)
                ->where(function ($query) use ($q) {
                    $query->where('nombre', 'like', "%{$q}%")
                        ->orWhere('cuit', 'like', preg_replace('/[^0-9]/', '', $q).'%');
                })
                ->limit(20)
                ->get()
                ->map(fn ($a) => [
                    'fuente' => 'local',
                    'id' => $a->id,
                    'tipo' => $a->tipo,
                    'codigo' => $a->codigo,
                    'nombre' => $a->nombre,
                    'cuit' => $a->cuit,
                    'tabla_ref' => $a->tabla_ref,
                    'id_ref' => $a->id_ref,
                ]);
        }

        $distriapp = collect();
        if ($fuente !== 'locales') {
            $distriapp = $data['tipo'] === 'Cliente'
                ? $this->bridge->buscarClientes($q)->map(fn ($c) => [
                    'fuente' => 'distriapp',
                    'tipo' => 'Cliente',
                    'distriapp_id' => $c->distriapp_id,
                    'nombre' => $c->razon_social,
                    'cuit' => $c->cuit,
                ])
                : $this->bridge->buscarPersonas($q)->map(fn ($p) => [
                    'fuente' => 'distriapp',
                    'tipo' => 'Distribuidor',
                    'distriapp_id' => $p->distriapp_id,
                    'nombre' => trim($p->nombre_completo ?? ''),
                    'cuit' => $p->cuil,
                ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $locales->concat($distriapp)->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['nullable', 'integer', 'exists:erp_empresas,id'],
            'tipo' => ['required', Rule::in(['Cliente', 'Distribuidor', 'Proveedor', 'Otro'])],
            'desde_distriapp' => ['nullable', 'array'],
            'desde_distriapp.persona_id' => ['nullable', 'integer'],
            'desde_distriapp.cliente_id' => ['nullable', 'integer'],
            'manual' => ['nullable', 'array'],
            'manual.codigo' => ['nullable', 'string', 'max:30'],
            'manual.nombre' => ['nullable', 'string', 'max:250'],
            'manual.cuit' => ['nullable', 'string', 'max:20'],
            'cuenta_contable_default_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
        ]);

        $empresaId = $data['empresa_id'] ?? ($request->user()->erpPerfil?->empresa_id ?? 1);
        // ADDENDUM v1.10 — autocomplete cuenta_default según tipo si no vino.
        $cuentaDefaultId = $data['cuenta_contable_default_id']
            ?? $this->cuentaDefaultPorTipo($empresaId, $data['tipo']);

        try {
            if (! empty($data['desde_distriapp']['persona_id'])) {
                $row = $this->bridge->crearDesdePersona((int) $data['desde_distriapp']['persona_id'], $empresaId);
                if ($cuentaDefaultId && empty($row['cuenta_contable_default_id'])) {
                    Auxiliar::where('id', $row['id'])->update(['cuenta_contable_default_id' => $cuentaDefaultId]);
                    $row['cuenta_contable_default_id'] = $cuentaDefaultId;
                }
            } elseif (! empty($data['desde_distriapp']['cliente_id'])) {
                $row = $this->bridge->crearDesdeCliente((int) $data['desde_distriapp']['cliente_id'], $empresaId);
                if ($cuentaDefaultId && empty($row['cuenta_contable_default_id'])) {
                    Auxiliar::where('id', $row['id'])->update(['cuenta_contable_default_id' => $cuentaDefaultId]);
                    $row['cuenta_contable_default_id'] = $cuentaDefaultId;
                }
            } elseif (! empty($data['manual'])) {
                $m = $data['manual'];
                $aux = Auxiliar::create([
                    'empresa_id' => $empresaId,
                    'tipo' => $data['tipo'],
                    'codigo' => $m['codigo'] ?? 'MAN-'.now()->timestamp,
                    'nombre' => $m['nombre'] ?? '',
                    'cuit' => isset($m['cuit']) ? preg_replace('/[^0-9]/', '', $m['cuit']) : null,
                    'cuenta_contable_default_id' => $cuentaDefaultId,
                    'activo' => true,
                ]);
                $row = $aux->toArray();
            } else {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'ENTRADA_INVALIDA', 'message' => 'Enviar desde_distriapp o manual.'],
                ], 422);
            }
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => explode(':', $e->getMessage(), 2)[0], 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['ok' => true, 'data' => $row], 201);
    }

    /**
     * ADDENDUM v1.10 — PATCH /auxiliares/{id} para actualizar la cuenta
     * default y otros campos editables del auxiliar. RN-CA-3 garantizado por
     * naturaleza: este update solo afecta operaciones futuras; los asientos
     * históricos ya fueron grabados con la cuenta vigente al momento de
     * asentar y no se tocan acá.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $auxiliar = Auxiliar::findOrFail($id);
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:250'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'activo' => ['nullable', 'boolean'],
            'cuenta_contable_default_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
        ]);
        if (isset($data['cuit']) && $data['cuit']) {
            $data['cuit'] = preg_replace('/[^0-9]/', '', $data['cuit']);
        }
        $auxiliar->update($data);
        return response()->json(['ok' => true, 'data' => $auxiliar->fresh()->load('cuentaDefault:id,codigo,nombre')]);
    }

    /**
     * Helper interno: devuelve el id de la cuenta default sugerida según
     * el tipo de auxiliar. NULL si no hay mapeo (Socio, Vehiculo, Bien, etc).
     */
    private function cuentaDefaultPorTipo(int $empresaId, string $tipo): ?int
    {
        $codigo = Auxiliar::CUENTA_DEFAULT_POR_TIPO[$tipo] ?? null;
        if (! $codigo) {
            return null;
        }
        $id = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', $codigo)
            ->value('id');
        return $id ? (int) $id : null;
    }

    public function saldo(Request $request, int $id): JsonResponse
    {
        $auxiliar = Auxiliar::findOrFail($id);

        $data = $request->validate([
            'periodo_id' => ['nullable', 'integer'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('m.auxiliar_id', $auxiliar->id)
            ->where('a.estado', 'CONTABILIZADO')
            ->when($data['periodo_id'] ?? null, fn ($q, $v) => $q->where('a.periodo_id', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('a.fecha', '<=', $v));

        $saldo = (float) $query->selectRaw('COALESCE(SUM(m.debe - m.haber), 0) AS saldo')->value('saldo');

        return response()->json([
            'ok' => true,
            'data' => [
                'auxiliar_id' => $auxiliar->id,
                'nombre' => $auxiliar->nombre,
                'saldo' => round($saldo, 2),
                'periodo_id' => $data['periodo_id'] ?? null,
                'hasta' => $data['hasta'] ?? null,
            ],
        ]);
    }
}
