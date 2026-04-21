<?php

namespace App\Erp\Console\Commands;

use App\Erp\Services\SaldosService;
use Illuminate\Console\Command;

class RebuildSaldos extends Command
{
    protected $signature = 'erp:rebuild-saldos {empresa_id : ID de la empresa a recomputar}';

    protected $description = 'Recomputa todos los saldos por (cuenta, período) de la empresa desde los movimientos contabilizados. Borra y reinserta.';

    public function handle(SaldosService $service): int
    {
        $empresaId = (int) $this->argument('empresa_id');

        $this->info("Recomputando saldos para empresa_id={$empresaId}…");
        $t0 = microtime(true);

        $count = $service->recomputarTodo($empresaId);

        $dt = round((microtime(true) - $t0) * 1000);
        $this->info("Listo: {$count} filas escritas en erp_saldos_cuenta en {$dt}ms.");

        return self::SUCCESS;
    }
}
