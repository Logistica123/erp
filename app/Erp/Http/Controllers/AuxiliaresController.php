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
    /** Tipos válidos del ENUM erp_auxiliares.tipo. */
    public const TIPOS = ['Cliente', 'Proveedor', 'Distribuidor', 'Empleado', 'Socio', 'Vehiculo', 'Sucursal', 'Colocacion', 'Bien', 'Organismo'];

    public function __construct(private readonly DistriAppBridge $bridge) {}

    /**
     * v1.55 Bloque C — listado para el ABM de Admin: paginado, con filtros
     * e inactivos. El GET /auxiliares de siempre (CatalogosController) es un
     * catálogo para selects (limit 50, solo activos) y no alcanza acá.
     */
    public function abmIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['nullable', Rule::in(self::TIPOS)],
            'q' => ['nullable', 'string', 'max:120'],
            'incluir_inactivos' => ['nullable', 'boolean'],
        ]);

        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        $auxiliares = Auxiliar::with('cuentaDefault:id,codigo,nombre')
            ->where('empresa_id', $empresaId)
            ->when($data['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when(! ($data['incluir_inactivos'] ?? false), fn ($q) => $q->where('activo', true))
            ->when($data['q'] ?? null, function ($query, $q) {
                $digitos = preg_replace('/[^0-9]/', '', $q);
                $query->where(function ($w) use ($q, $digitos) {
                    $w->where('nombre', 'like', "%{$q}%")
                        ->orWhere('codigo', 'like', "%{$q}%");
                    if ($digitos !== '') {
                        $w->orWhere('cuit', 'like', "{$digitos}%");
                    }
                });
            })
            ->orderBy('tipo')->orderBy('nombre')
            ->paginate(50);

        return response()->json($auxiliares);
    }

    /** v1.55 Bloque C — reactivar auxiliar dado de baja (simétrico a desactivar). */
    public function reactivar(int $id): JsonResponse
    {
        $aux = Auxiliar::findOrFail($id);
        $aux->update(['activo' => true]);

        return response()->json(['ok' => true, 'data' => $aux->fresh('cuentaDefault:id,codigo,nombre')]);
    }

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
            // v1.55 Bloque C — alta manual admite todos los tipos del ENUM.
            'tipo' => ['required', Rule::in(self::TIPOS)],
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

    /**
     * GET /api/erp/auxiliares/by-cuit/{cuit}?tipo=Cliente|Proveedor
     * v1.17 — helper para form de carga manual: busca por CUIT y devuelve datos del auxiliar.
     */
    public function byCuit(Request $request, string $cuit): JsonResponse
    {
        $tipo = $request->query('tipo', 'Cliente');
        $aux = DB::table('erp_auxiliares')
            ->where('cuit', $cuit)
            ->where('tipo', $tipo)
            ->where('activo', 1)
            ->select('id', 'codigo', 'nombre', 'cuit', 'tipo', 'cuenta_contable_default_id')
            ->first();
        if (! $aux) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        return response()->json(['ok' => true, 'data' => $aux]);
    }

    /**
     * GET /api/erp/auxiliares/{id}/cc-asociado — v1.15 ampliación (CC-09).
     *
     * Devuelve el CC asociado a este auxiliar (si es tipo Cliente) con su
     * movimientos_count. El frontend lo usa para el modal de baja —
     * el operador decide si desactivar también el CC o mantenerlo visible
     * para consultas históricas.
     */
    public function ccAsociado(Request $request, int $id): JsonResponse
    {
        $aux = Auxiliar::find($id);
        if (! $aux) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        if (! $cc) {
            return response()->json(['ok' => true, 'data' => null]);
        }
        $movs = (int) DB::table('erp_movimientos_asiento')->where('centro_costo_id', $cc->id)->count();
        return response()->json([
            'ok' => true,
            'data' => [
                'cc_id' => (int) $cc->id,
                'codigo' => $cc->codigo,
                'nombre' => $cc->nombre,
                'tipo' => $cc->tipo,
                'activo' => (bool) $cc->activo,
                'movimientos_count' => $movs,
            ],
        ]);
    }

    /**
     * POST /api/erp/auxiliares/{id}/desactivar — v1.15 ampliación (CC-09).
     *
     * Desactiva el auxiliar (tipo Cliente) en transacción y, opcionalmente,
     * también el CC asociado. El operador elige `desactivar_cc` en el modal.
     */
    public function desactivar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'desactivar_cc' => ['nullable', 'boolean'],
        ]);

        $aux = Auxiliar::find($id);
        if (! $aux) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO']], 404);
        }
        if (! $aux->activo) {
            return response()->json(['ok' => true, 'data' => ['mensaje' => 'Ya estaba inactivo.']]);
        }

        $userId = $request->user()->id;
        $desactivarCc = (bool) ($data['desactivar_cc'] ?? false);

        DB::transaction(function () use ($aux, $desactivarCc, $userId) {
            $aux->update(['activo' => 0]);

            if ($desactivarCc) {
                $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
                if ($cc && $cc->activo) {
                    DB::table('erp_centros_costo')
                        ->where('id', $cc->id)
                        ->update([
                            'activo' => 0,
                            'eliminada_at' => now(),
                            'eliminada_por' => $userId,
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'auxiliar_id' => $aux->id,
                'cc_desactivado' => $desactivarCc,
            ],
        ]);
    }
}
