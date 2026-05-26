<?php

namespace App\Erp\Jobs;

use App\Erp\Services\Tesoreria\OrdenesPagoSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * v1.35 — Cron de sync incremental de Órdenes de Pago desde DistriApp.
 * Corre cada 15 min (ver routes/console.php), withoutOverlapping.
 */
class SyncOrdenesPagoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(OrdenesPagoSyncService $service): void
    {
        $r = $service->syncIncremental();
        if ($r['creadas'] > 0 || $r['actualizadas'] > 0 || ! empty($r['errores'])) {
            Log::info('Sync OP incremental', $r);
        }
    }
}
