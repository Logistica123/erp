<?php

namespace App\Erp\Services\Integracion;

use App\Erp\Models\VentasCompras\FacturaCompra;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v1.54 §8.3 — Webhook reverso ERP → DistriApp.
 *
 * Best-effort: si DistriApp no responde o no está configurado, se loguea y la
 * operación del ERP sigue (el log queda en erp_facturas_compra_sync_log para
 * reprocesar a mano si hace falta).
 */
class DistriAppNotificaciones
{
    public function notificarDesvinculacion(FacturaCompra $factura, string $evento, string $motivo): void
    {
        $base = rtrim((string) config('services.distriapp.base_url'), '/');
        $secret = (string) config('services.distriapp.webhook_secret');

        if ($base === '' || $secret === '' || ! $factura->distriapp_factura_id) {
            $this->log($factura, $evento, null, 'DistriApp no configurado (services.distriapp) — webhook reverso omitido.');

            return;
        }

        $body = [
            'erp_factura_compra_id' => $factura->id,
            'evento' => $evento,
            'timestamp' => now()->toIso8601String(),
            'motivo' => $motivo,
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $url = "{$base}/api/facturas/{$factura->distriapp_factura_id}/desvincular-de-erp";

        try {
            $resp = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$secret,
                    'X-Erp-Event' => $evento,
                    'X-Erp-Signature' => hash_hmac('sha256', $json, $secret),
                ])
                ->withBody($json, 'application/json')
                ->post($url);
            $this->log($factura, $evento, $resp->status(), mb_substr($resp->body(), 0, 2000));
        } catch (\Throwable $e) {
            Log::warning('v1.54 webhook reverso a DistriApp falló', ['factura' => $factura->id, 'error' => $e->getMessage()]);
            $this->log($factura, $evento, null, 'EXCEPCION: '.$e->getMessage());
        }
    }

    private function log(FacturaCompra $factura, string $evento, ?int $codigo, string $body): void
    {
        DB::table('erp_facturas_compra_sync_log')->insert([
            'factura_compra_id' => $factura->id,
            'distriapp_factura_id' => (string) $factura->distriapp_factura_id,
            'evento' => $evento === 'BORRADA_EN_ERP' ? 'BORRADA' : 'DESAUTORIZADA',
            'direccion' => 'ERP_A_DISTRIAPP',
            'payload' => json_encode(['evento' => $evento], JSON_UNESCAPED_UNICODE),
            'respuesta_codigo' => $codigo,
            'respuesta_body' => $body,
            'procesado_at' => now(),
        ]);
    }
}
