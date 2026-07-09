<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\CargaSaldoInicial;
use App\Erp\Services\Tesoreria\CargaSaldoInicialService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v1.52 — Carga de Saldo Inicial (Cajas y Bancos).
 *
 *   GET  /api/erp/tesoreria/cargas-saldo-inicial                 listado con filtros
 *   GET  /api/erp/tesoreria/cargas-saldo-inicial/cuentas-destino catálogo cuentas elegibles + contrapartida default
 *   POST /api/erp/tesoreria/cargas-saldo-inicial                 crea carga + asiento
 *   POST /api/erp/tesoreria/cargas-saldo-inicial/{id}/revertir   reversa con motivo
 */
class CargasSaldoInicialController
{
    public function __construct(private readonly CargaSaldoInicialService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_id' => ['nullable', 'integer'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'estado' => ['nullable', 'in:ACTIVO,REVERTIDO'],
        ]);

        $query = CargaSaldoInicial::query()
            ->with([
                'cuentaDestino:id,codigo,nombre', 'cuentaContrapartida:id,codigo,nombre',
                'asiento:id,numero,fecha', 'asientoReversa:id,numero,fecha',
                'creadoPor:id,name', 'revertidoPor:id,name',
                'caja:id,codigo,nombre', 'cuentaBancaria:id,codigo,nombre',
            ])
            ->when($data['cuenta_id'] ?? null, fn ($q, $v) => $q->where('cuenta_contable_destino_id', $v))
            ->when($data['fecha_desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($data['fecha_hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->when($data['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('fecha')->orderByDesc('id');

        return response()->json(['ok' => true, 'data' => $query->paginate(50)]);
    }

    public function cuentasDestino(): JsonResponse
    {
        $contrapartida = \App\Erp\Models\CuentaContable::where('empresa_id', 1)
            ->where('codigo', CargaSaldoInicialService::CODIGO_CONTRAPARTIDA_DEFAULT)
            ->first(['id', 'codigo', 'nombre']);

        return response()->json(['ok' => true, 'data' => [
            'cuentas' => $this->service->cuentasDestino(),
            'contrapartida_default' => $contrapartida,
            'motivos' => CargaSaldoInicialService::MOTIVOS,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_contable_destino_id' => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha' => ['required', 'date'],
            'motivo_tipo' => ['required', 'in:APERTURA_EJERCICIO,PUESTA_MARCHA_MODULO,REGULARIZACION_ESTUDIO,OTRO'],
            'motivo_observacion' => ['nullable', 'string', 'max:500'],
            'cuenta_contable_contrapartida_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
        ]);

        try {
            $carga = $this->service->crear([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $carga, 'asiento_id' => $carga->asiento_id], 201);
    }

    public function revertir(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['motivo_reversa' => ['required', 'string', 'min:10', 'max:500']]);
        $carga = CargaSaldoInicial::findOrFail($id);

        try {
            $carga = $this->service->revertir($carga, $data['motivo_reversa'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $carga, 'asiento_reversa_id' => $carga->asiento_reversa_id]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 422);
    }
}
