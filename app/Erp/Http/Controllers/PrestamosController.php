<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Prestamos\ParserPlanAfip;
use App\Erp\Services\PrestamosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrestamosController
{
    public function __construct(private readonly PrestamosService $service) {}

    /** Analiza un PDF "Mis Facilidades" de ARCA/AFIP y devuelve el preview (sin guardar). */
    public function analizarPlanAfip(Request $request): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'mimetypes:application/pdf']]);
        $texto = $this->pdfATexto($request->file('archivo')->getRealPath());
        try {
            $plan = (new ParserPlanAfip())->parse($texto);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $plan]);
    }

    /** Importa el plan AFIP del PDF como préstamo RECIBIDO con su cronograma. */
    public function importarPlanAfip(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimetypes:application/pdf'],
            'empresa_id' => ['nullable', 'integer'],
        ]);
        $empresaId = (int) ($request->input('empresa_id') ?: ($request->header('X-Empresa-Id') ?: 1));
        $texto = $this->pdfATexto($request->file('archivo')->getRealPath());
        try {
            $res = $this->service->importarPlanAfip($texto, $empresaId, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $res], 201);
    }

    private function pdfATexto(string $path): string
    {
        $out = []; $rc = 0;
        @exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null', $out, $rc);
        return implode("\n", $out);
    }

    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) $request->query('empresa_id', 1);
        $tipo = $request->query('tipo') ?: null;
        $estado = $request->query('estado') ?: 'VIGENTE';
        if ($estado === 'TODOS') $estado = null;
        return response()->json(['ok' => true, 'data' => $this->service->listar($empresaId, $tipo, $estado)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:erp_empresas,id'],
            'tipo' => ['required', 'in:OTORGADO,RECIBIDO'],
            'contraparte_auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'nombre' => ['required', 'string', 'max:150'],
            'capital' => ['required', 'numeric', 'min:0.01'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'tasa_mensual' => ['nullable', 'numeric', 'min:0'],
            'tasa_nominal_anual' => ['nullable', 'numeric', 'min:0'],
            'sistema_amortizacion' => ['nullable', 'in:FRANCES,ALEMAN,AMERICANO,BULLET'],
            'plazo_cuotas' => ['required', 'integer', 'min:1'],
            'fecha_otorgamiento' => ['required', 'date'],
            'fecha_primera_cuota' => ['required', 'date'],
            'cuenta_contable_id' => ['nullable', 'integer'],
            'observaciones' => ['nullable', 'string'],
        ]);
        try {
            $id = $this->service->crear([...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => ['id' => $id]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'data' => $this->service->detalle($id)]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function pagarCuota(Request $request, int $id, int $cuotaId): JsonResponse
    {
        $data = $request->validate([
            'fecha_pago' => ['required', 'date'],
            'importe_pagado' => ['required', 'numeric', 'min:0.01'],
            'op_pago_id' => ['nullable', 'integer'],
            'recibo_cobro_id' => ['nullable', 'integer'],
            'medio_pago_id' => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $this->service->pagarCuota($id, $cuotaId, [...$data, 'usuario_id' => $request->user()->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true]);
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
            'incobrable' => ['nullable', 'boolean'],
        ]);
        try {
            $this->service->cancelar($id, $data['motivo'], $request->user()->id, (bool) ($data['incobrable'] ?? false));
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
