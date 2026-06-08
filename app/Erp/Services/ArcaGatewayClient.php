<?php

namespace App\Erp\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP tipado del ArcaGateway.
 *
 * Todas las operaciones pasan Headers X-Client-Id / X-API-Key y timeout corto.
 * Errores de red se propagan (no atrapar acá — el caller decide qué hacer).
 */
class ArcaGatewayClient
{
    private function request(): PendingRequest
    {
        $cfg = config('services.arca_gateway', []);
        return Http::baseUrl($cfg['url'] ?? 'http://127.0.0.1:8000')
            ->withHeaders([
                'X-Client-Id' => $cfg['client_id'] ?? null,
                'X-API-Key' => $cfg['api_key'] ?? null,
            ])
            ->timeout((int) ($cfg['timeout'] ?? 35))
            ->acceptJson();
    }

    public function healthReady(): Response
    {
        return $this->request()->get('/health/ready');
    }

    public function puntosVenta(): Response
    {
        return $this->request()->get('/wsfe/puntos-venta');
    }

    public function ultimoAutorizado(int $tipoCbte, int $ptoVta): Response
    {
        return $this->request()->get("/wsfe/ultimo-autorizado/{$tipoCbte}/{$ptoVta}");
    }

    public function consultar(int $tipoCbte, int $ptoVta, int $nroCbte): Response
    {
        return $this->request()->get("/wsfe/consultar/{$tipoCbte}/{$ptoVta}/{$nroCbte}");
    }

    public function cotizacion(string $moneda): Response
    {
        return $this->request()->get("/wsfe/cotizacion/{$moneda}");
    }

    public function emitir(array $payload): Response
    {
        return $this->request()->post('/wsfe/emitir', $payload);
    }

    /**
     * Consulta padrón AFIP (WS_A5 / A13) por CUIT — razón social, condición
     * IVA, estado, domicilio fiscal, actividades.
     */
    public function padron(string $cuit): Response
    {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);

        return $this->request()->get("/padron/{$cuit}");
    }

    /**
     * Constata CAE de un comprobante recibido (WSCDC) — devuelve A (válido)
     * / R (rechazado) más datos AFIP. Endpoint real del gateway:
     * `/comprobantes/constatar` (introducido en v1.28+; `/wsfe/constatar`
     * era un alias que nunca existió en el gateway).
     */
    public function constatar(array $payload): Response
    {
        // Gateway espera shape ConstatacionRequest (snake_case AFIP).
        $body = [
            'cuit_emisor' => preg_replace('/[^0-9]/', '', (string) ($payload['cuit_emisor'] ?? '')),
            'tipo_cbte' => (int) ($payload['tipo_cbte'] ?? $payload['tipo'] ?? 0),
            'pto_vta' => (int) ($payload['pto_vta'] ?? 0),
            'nro_cbte' => (int) ($payload['cbte_nro'] ?? $payload['numero'] ?? 0),
            'cae' => (string) ($payload['cae'] ?? ''),
            'fecha_emision' => $payload['fecha_cbte'] ?? $payload['fecha_emision'] ?? null,
            'importe_total' => (float) ($payload['imp_total'] ?? $payload['importe_total'] ?? 0),
            'cuit_receptor' => preg_replace('/[^0-9]/', '',
                (string) ($payload['cuit_receptor'] ?? config('services.arca_gateway.cuit_representado') ?? '30717060985')),
            'doc_tipo_receptor' => (int) ($payload['doc_tipo_receptor'] ?? 80),
        ];
        return $this->request()->post('/comprobantes/constatar', $body);
    }

    /**
     * Dispara scraper Mis Comprobantes → Recibidos para un rango de fechas.
     * Timeout extendido (scraper puede tardar).
     */
    public function misComprobantesRecibidos(string $desde, string $hasta): Response
    {
        return $this->request()->timeout(180)->post('/mis-comprobantes/recibidos', [
            'desde' => $desde,
            'hasta' => $hasta,
        ]);
    }

    public function misComprobantesEmitidos(string $desde, string $hasta): Response
    {
        return $this->request()->timeout(180)->post('/mis-comprobantes/emitidos', [
            'desde' => $desde,
            'hasta' => $hasta,
        ]);
    }
}
