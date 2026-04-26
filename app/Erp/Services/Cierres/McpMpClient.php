<?php

namespace App\Erp\Services\Cierres;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Cliente Mercado Pago Reports API — "Account money".
 * Doc: https://www.mercadopago.com.ar/developers/es/reference/account_balance/_users_account_bank_report/post
 *
 * Pipeline (anexo §9.2):
 *   1) POST /v1/account/bank_report con date_from + date_to + type=account_money.
 *   2) Polling cada N segundos (default 5s) hasta que status=='ready' (max 120s).
 *   3) GET /v1/account/bank_report/{id}/download → CSV idéntico al panel.
 *
 * Nota: la skill cierres-contables tenía un MCP custom `mp_movimientos`. Se
 * descartó porque el MCP oficial de MP no expone payments/movs (verificado
 * 2026-04-25 contra doc oficial). Esta implementación va directo al REST API.
 */
class McpMpClient
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $apiBase = 'https://api.mercadopago.com',
        private readonly int $timeoutSec = 120,
        private readonly int $pollIntervalSec = 5,
    ) {}

    public static function fromConfig(): self
    {
        $token = (string) config('services.mp.access_token', env('MP_ACCESS_TOKEN', ''));
        if ($token === '') {
            throw new DomainException('MP_TOKEN_FALTANTE: MP_ACCESS_TOKEN no configurado');
        }
        return new self(
            accessToken:    $token,
            apiBase:        (string) (env('MP_API_BASE') ?: 'https://api.mercadopago.com'),
            timeoutSec:     (int) (env('MP_REPORT_TIMEOUT_SECONDS') ?: 120),
            pollIntervalSec: (int) (env('MP_REPORT_POLL_INTERVAL_SECONDS') ?: 5),
        );
    }

    /**
     * Solicita un reporte bank_report Account money para el rango.
     * @return string $reportId
     */
    public function solicitarReporte(Carbon $desde, Carbon $hasta): string
    {
        $resp = Http::withToken($this->accessToken)
            ->acceptJson()->asJson()
            ->timeout(30)
            ->post($this->apiBase.'/v1/account/bank_report', [
                'date_from' => $desde->copy()->startOfDay()->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d\TH:i:s.vP'),
                'date_to'   => $hasta->copy()->endOfDay()->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d\TH:i:s.vP'),
                'type'      => 'account_money',
            ]);
        if (! $resp->ok()) {
            throw new DomainException(sprintf(
                'MP_REPORT_REQUEST_FALLO: HTTP %d · %s',
                $resp->status(), substr((string) $resp->body(), 0, 250)
            ));
        }
        $reportId = (string) ($resp->json('id') ?? '');
        if ($reportId === '') {
            throw new DomainException('MP_REPORT_REQUEST_FALLO: respuesta sin id de reporte');
        }
        return $reportId;
    }

    /**
     * Polling hasta que el reporte esté listo o timeout.
     * @return array{ready: bool, status: string}
     */
    public function pollearEstado(string $reportId): array
    {
        $start = time();
        $lastStatus = 'unknown';
        while ((time() - $start) < $this->timeoutSec) {
            $r = Http::withToken($this->accessToken)->acceptJson()->timeout(20)
                ->get($this->apiBase.'/v1/account/bank_report/'.$reportId);
            if ($r->ok()) {
                $lastStatus = (string) ($r->json('status') ?? 'unknown');
                if (in_array($lastStatus, ['ready', 'available', 'completed'], true)) {
                    return ['ready' => true, 'status' => $lastStatus];
                }
                if (in_array($lastStatus, ['failed', 'error'], true)) {
                    return ['ready' => false, 'status' => $lastStatus];
                }
            }
            sleep($this->pollIntervalSec);
        }
        return ['ready' => false, 'status' => 'timeout:'.$lastStatus];
    }

    /**
     * Descarga el CSV del reporte y lo guarda en storage/temp.
     * @return string $pathAbsoluto al CSV en disco (caller lo borra cuando termina)
     */
    public function descargarCsv(string $reportId): string
    {
        $r = Http::withToken($this->accessToken)->timeout(60)
            ->get($this->apiBase.'/v1/account/bank_report/'.$reportId.'/download');
        if (! $r->ok()) {
            throw new DomainException(sprintf(
                'MP_REPORT_DOWNLOAD_FALLO: HTTP %d · %s',
                $r->status(), substr((string) $r->body(), 0, 250)
            ));
        }
        $contenido = (string) $r->body();
        if ($contenido === '') {
            throw new DomainException('MP_REPORT_DOWNLOAD_VACIO: el reporte llegó vacío');
        }

        $dir = storage_path('app/cierres/mp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, recursive: true);
        }
        $path = $dir.'/mp_'.$reportId.'_'.now()->format('YmdHis').'.csv';
        file_put_contents($path, $contenido);
        return $path;
    }

    /**
     * Helper one-shot: solicita + pollea + descarga, devuelve path local del CSV.
     * @throws DomainException
     */
    public function obtenerMovimientos(Carbon $desde, Carbon $hasta): string
    {
        $reportId = $this->solicitarReporte($desde, $hasta);

        $intentos = 0;
        $maxIntentos = 3;
        while ($intentos < $maxIntentos) {
            $estado = $this->pollearEstado($reportId);
            if ($estado['ready']) {
                return $this->descargarCsv($reportId);
            }
            $intentos++;
            if (str_starts_with($estado['status'], 'timeout')) {
                throw new DomainException('MP_TIMEOUT: reporte no quedó ready en '.$this->timeoutSec.'s. Caer al fallback de upload manual.');
            }
            // backoff: 2s, 5s, 10s entre reintentos.
            sleep(match ($intentos) { 1 => 2, 2 => 5, default => 10 });
        }
        throw new DomainException('MP_REPORT_FALLO_REPETIDO: '.$maxIntentos.' intentos sin éxito. Caer al fallback de upload manual.');
    }
}
