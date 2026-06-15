<?php

namespace App\Console\Commands;

use App\Erp\Services\Conciliacion\ReglasConciliacionV147Importer;
use Illuminate\Console\Command;

class SeedReglasConciliacionV147 extends Command
{
    protected $signature = 'reglas-conciliacion:seed-v147
        {--dry-run : Simula sin escribir}
        {--confirm : Ejecuta la carga real}
        {--user-id=1 : Usuario para audit}
        {--json= : Path al JSON}';

    protected $description = 'v1.47 — 3 cuentas + 5 auxiliares + 7 regex ajustadas + 42 reglas nuevas.';

    public function handle(ReglasConciliacionV147Importer $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->option('confirm')) {
            $this->error('Especificar --dry-run o --confirm.');
            return self::FAILURE;
        }
        $jsonPath = $this->option('json') ?: database_path('seeders/data/reglas_conciliacion_v147.json');
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Seeder reglas conciliación v1.47 desde ' . $jsonPath);
        try {
            $r = $importer->run($jsonPath, (int) $this->option('user-id'), $dryRun);
            $this->line(json_encode($r['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info($dryRun ? '[DRY-RUN OK] nada persistido.' : 'Seeder aplicado.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('FALLA: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
