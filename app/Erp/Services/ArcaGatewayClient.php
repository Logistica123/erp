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
        return Http::baseUrl(config('services.arca.gateway_url'))
            ->withHeaders([
                'X-Client-Id' => config('services.arca.client_id'),
                'X-API-Key' => config('services.arca.api_key'),
            ])
            ->timeout(35)
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
     * Constata CAE de un comprobante recibido (WS COMP_CONSULT) —
     * devuelve VALIDO / INVALIDO / NO_ENCONTRADO más datos de AFIP.
     */
    public function constatar(array $payload): Response
    {
        return $this->request()->post('/wsfe/constatar', $payload);
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
