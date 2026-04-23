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
}
