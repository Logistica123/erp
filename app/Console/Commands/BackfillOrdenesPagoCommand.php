<?php

namespace App\Console\Commands;

use App\Erp\Services\Tesoreria\OrdenesPagoSyncService;
use Illuminate\Console\Command;

/**
 * v1.35 — Backfill completo de Órdenes de Pago desde DistriApp.
 *
 *   php artisan op:backfill-distriapp            (dry-run, solo cuenta)
 *   php artisan op:backfill-distriapp --confirm  (ejecuta)
 *
 * Idempotente: usa distriapp_op_id como unique key.
 */
class BackfillOrdenesPagoCommand extends Command
{
    protected $signature = 'op:backfill-distriapp {--confirm : Ejecuta el backfill (sin esta flag es dry-run)}';
    protected $description = 'Backfill de órdenes de pago desde DistriApp (basepersonal.liq_ordenes_pago)';

    public function handle(OrdenesPagoSyncService $service): int
    {
        $dryRun = ! $this->option('confirm');
        $this->info($dryRun ? '🔍 DRY-RUN (sin escribir). Usá --confirm para ejecutar.' : '⚙️  Ejecutando backfill…');

        $r = $service->backfillCompleto($dryRun);

        if ($dryRun) {
            $this->line("Traería ~{$r['creadas']} órdenes de pago de DistriApp.");
        } else {
            $this->info("✅ Creadas: {$r['creadas']} · Actualizadas: {$r['actualizadas']} · Sin cambios: {$r['sinCambios']}");
        }
        if (! empty($r['errores'])) {
            $this->error(count($r['errores']) . ' errores:');
            foreach (array_slice($r['errores'], 0, 20) as $e) {
                $this->line("  - OP {$e['distriapp_op_id']}: {$e['error']}");
            }
        }
        return self::SUCCESS;
    }
}
