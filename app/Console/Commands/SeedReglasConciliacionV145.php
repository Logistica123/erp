<?php

namespace App\Console\Commands;

use App\Erp\Services\Conciliacion\ReglasConciliacionV145Importer;
use Illuminate\Console\Command;

/**
 * v1.45 — Seeder de reglas de conciliación.
 *   php artisan reglas-conciliacion:seed-v145 --dry-run
 *   php artisan reglas-conciliacion:seed-v145 --confirm
 */
class SeedReglasConciliacionV145 extends Command
{
    protected $signature = 'reglas-conciliacion:seed-v145
        {--dry-run : Simula sin escribir (rollback al final)}
        {--confirm : Ejecuta la carga real}
        {--user-id=1 : Usuario para audit}
        {--json= : Path al JSON (default seeders/data/reglas_conciliacion_v145.json)}';

    protected $description = 'v1.45 — Asigna cuentas a reglas existentes + crea reglas nuevas + extractor CUIT.';

    public function handle(ReglasConciliacionV145Importer $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->option('confirm')) {
            $this->error('Especificar --dry-run o --confirm.');
            return self::FAILURE;
        }
        $jsonPath = $this->option('json') ?: database_path('seeders/data/reglas_conciliacion_v145.json');
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Seeder reglas conciliación v1.45 desde ' . $jsonPath);

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
