<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\MovimientoBancarioService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovimientosBancariosController
{
    public function __construct(private readonly MovimientoBancarioService $service) {}

    /**
     * GET /api/erp/movimientos-bancarios
     *   ?cuenta_bancaria_id=
     *   ?estado=PENDIENTE|ETIQUETADO|CONCILIADO|IGNORADO
     *   ?desde=&hasta=
     */
    public function index(Request $request): JsonResponse
    {
        $query = MovimientoBancario::query()
            ->with(['cuentaBancaria:id,codigo,nombre,banco_id', 'cuentaBancaria.banco:id,codigo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id');

        if ($cb = $request->integer('cuenta_bancaria_id')) {
            $query->where('cuenta_bancaria_id', $cb);
        }
        if ($estado = $request->string('estado')->toString()) {
            $query->where('estado', $estado);
        }
        if ($desde = $request->date('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->date('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
            'meta' => ['limit' => 200],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'fecha_valor' => ['nullable', 'date'],
            'concepto' => ['required', 'string', 'max:500'],
            'comprobante_banco' => ['nullable', 'string', 'max:100'],
            'debito' => ['nullable', 'numeric', 'min:0'],
            'credito' => ['nullable', 'numeric', 'min:0'],
        ]);

        $debito = (float) ($data['debito'] ?? 0);
        $credito = (float) ($data['credito'] ?? 0);
        if ($debito == 0 && $credito == 0) {
            return response()->json(['error' => ['code' => 'IMPORTE_CERO', 'message' => 'Debe informar débito o crédito > 0']], 422);
        }
        if ($debito > 0 && $credito > 0) {
            return response()->json(['error' => ['code' => 'UN_LADO', 'message' => 'Informar débito XOR crédito, no ambos.']], 422);
        }

        try {
            $mov = $this->service->crearManual([
                ...$data,
                'usuario_id' => $request->user()->id,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $mov->load('cuentaBancaria.banco')], 201);
    }

    public function conciliar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'cuenta_contable_id' => ['required', 'integer'],
            'centro_costo_id' => ['nullable', 'integer'],
            'auxiliar_id' => ['nullable', 'integer'],
            'glosa' => ['nullable', 'string', 'max:500'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);

        try {
            $mov = $this->service->conciliar(
                mov: $mov,
                cuentaContableContraparteId: $data['cuenta_contable_id'],
                usuarioId: $request->user()->id,
                centroCostoId: $data['centro_costo_id'] ?? null,
                auxiliarId: $data['auxiliar_id'] ?? null,
                glosa: $data['glosa'] ?? null,
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $mov->load('cuentaBancaria.banco', 'asiento')]);
    }

    public function ignorar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo_ignorado_id' => ['required', 'integer'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $mov = MovimientoBancario::findOrFail($id);

        try {
            $mov = $this->service->ignorar($mov, $data['motivo_ignorado_id'], $data['observacion'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['data' => $mov]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
