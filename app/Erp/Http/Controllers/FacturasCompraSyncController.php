<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\Integracion\SyncFacturaCompraDistriAppService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.54 — Sync facturas de compra DistriApp ↔ ERP.
 *
 * Webhooks entrantes (públicos, Bearer + HMAC — SIN sanctum):
 *   POST /api/erp/compras/facturas-compra/sync-from-distriapp
 *   POST /api/erp/compras/facturas-compra/sync-delete-from-distriapp
 *
 * Acciones de UI (auth normal):
 *   GET  /api/erp/facturas-compra/{id}/preview-autorizacion
 *   POST /api/erp/facturas-compra/{id}/autorizar
 *   POST /api/erp/facturas-compra/{id}/desautorizar
 *   DELETE /api/erp/facturas-compra/{id}/sync
 */
class FacturasCompraSyncController
{
    public function __construct(private readonly SyncFacturaCompraDistriAppService $service) {}

    // ------------------------------------------------------------------
    // Webhooks entrantes
    // ------------------------------------------------------------------

    public function syncFromDistriapp(Request $request): JsonResponse
    {
        if ($fail = $this->verificarFirma($request)) {
            return $fail;
        }

        $payload = $request->json()->all();

        try {
            $factura = $this->service->sincronizar($payload);
        } catch (DomainException $e) {
            return $this->mapearError($e, $payload);
        }

        return response()->json([
            'erp_factura_compra_id' => $factura->id,
            'estado_erp' => $factura->estado,
            'auxiliar_proveedor_id' => $factura->auxiliar_id,
            'mensaje' => 'Factura sincronizada correctamente',
        ], 201);
    }

    public function syncDeleteFromDistriapp(Request $request): JsonResponse
    {
        if ($fail = $this->verificarFirma($request)) {
            return $fail;
        }

        $resultado = $this->service->borrarDesdeDistriApp($request->json()->all());

        return response()->json($resultado['body'], $resultado['status']);
    }

    // ------------------------------------------------------------------
    // Acciones desde la UI del ERP
    // ------------------------------------------------------------------

    public function previewAutorizacion(int $id): JsonResponse
    {
        $factura = FacturaCompra::with(['auxiliar:id,codigo,nombre,tipo', 'tipoComprobante:id,codigo_interno,nombre,letra'])->findOrFail($id);
        if ($factura->estado !== 'PENDIENTE_AUTORIZACION_ERP') {
            return response()->json(['error' => ['code' => 'FACTURA_NO_ESTA_PENDIENTE', 'message' => "Estado actual {$factura->estado}."]], 422);
        }
        $cuentas = $this->service->previewCuentas($factura);

        $lineas = [];
        $neto = (float) $factura->imp_neto_gravado + (float) $factura->imp_no_gravado + (float) $factura->imp_exento;
        $iva = (float) $factura->imp_iva;
        $total = (float) $factura->imp_total;
        if ($neto == 0.0 && $iva == 0.0) {
            $neto = $total; // comprobantes sin IVA discriminado (Factura C)
        }
        if ($neto > 0) {
            $lineas[] = ['lado' => 'D', 'cuenta' => $cuentas['cuenta_gasto']->codigo.' '.$cuentas['cuenta_gasto']->nombre, 'importe' => round($neto, 2)];
        }
        if ($iva > 0) {
            $lineas[] = ['lado' => 'D', 'cuenta' => '1.1.6.01.x IVA Crédito Fiscal (por alícuota)', 'importe' => round($iva, 2)];
        }
        $otros = round($total - $neto - $iva, 2);
        if ($otros > 0.009) {
            $lineas[] = ['lado' => 'D', 'cuenta' => 'Percepciones / tributos (según desglose)', 'importe' => $otros];
        }
        $lineas[] = ['lado' => 'H', 'cuenta' => $cuentas['cuenta_contrapartida']->codigo.' '.$cuentas['cuenta_contrapartida']->nombre.' ('.$factura->auxiliar?->nombre.')', 'importe' => round($total, 2)];

        return response()->json(['ok' => true, 'data' => [
            'factura' => $factura,
            'cliente_liquidacion' => $cuentas['cliente'],
            'lineas' => $lineas,
            'fecha_asiento' => optional($factura->fecha_imputacion)->toDateString() ?? optional($factura->fecha_emision)->toDateString(),
        ]]);
    }

    public function autorizar(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::findOrFail($id);
        try {
            $factura = $this->service->autorizar($factura, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura, 'asiento_id' => $factura->asiento_id, 'estado_erp' => $factura->estado]);
    }

    public function desautorizar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        $factura = FacturaCompra::findOrFail($id);
        try {
            $factura = $this->service->desautorizar($factura, $data['motivo'], $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function borrar(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::findOrFail($id);
        try {
            $this->service->borrarDesdeErp($factura, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Verifica Bearer + firma HMAC-SHA256 del body con el secret compartido. */
    private function verificarFirma(Request $request): ?JsonResponse
    {
        $secret = (string) config('services.distriapp.webhook_secret');
        if ($secret === '') {
            return response()->json(['error' => 'SYNC_NO_CONFIGURADO', 'detalle' => 'Falta DISTRIAPP_WEBHOOK_SECRET en el ERP.'], 503);
        }

        $bearer = (string) $request->bearerToken();
        $firma = (string) $request->header('X-Distriapp-Signature');
        $esperada = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($secret, $bearer) || $firma === '' || ! hash_equals($esperada, $firma)) {
            return response()->json(['error' => 'UNAUTHORIZED', 'detalle' => 'Token o firma HMAC inválidos.'], 401);
        }

        return null;
    }

    private function mapearError(DomainException $e, array $payload): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        $status = match ($code) {
            'FACTURA_YA_SINCRONIZADA', 'COMPROBANTE_DUPLICADO' => 409,
            default => 422,
        };

        // 409 idempotente informa el estado actual de la factura existente.
        $extra = [];
        if ($code === 'FACTURA_YA_SINCRONIZADA') {
            $existente = FacturaCompra::withTrashed()
                ->where('distriapp_factura_id', $payload['distriapp_factura_id'] ?? '')
                ->first();
            if ($existente) {
                $extra = ['erp_factura_compra_id' => $existente->id, 'estado_erp' => $existente->estado];
            }
        }

        DB::table('erp_facturas_compra_sync_log')->insert([
            'factura_compra_id' => $extra['erp_factura_compra_id'] ?? null,
            'distriapp_factura_id' => (string) ($payload['distriapp_factura_id'] ?? 's/d'),
            'evento' => 'ERROR',
            'direccion' => 'DISTRIAPP_A_ERP',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'respuesta_codigo' => $status,
            'respuesta_body' => $e->getMessage(),
            'procesado_at' => now(),
        ]);

        return response()->json(['error' => $code, 'detalle' => $e->getMessage(), ...$extra], $status);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 422);
    }
}
