<?php

namespace App\Console\Commands;

use App\Erp\Services\AperturaEstudio\AperturaEstudioImporter;
use Illuminate\Console\Command;

/**
 * v1.43 — Comando para cargar la apertura contable entregada por el estudio.
 *
 * Uso:
 *   php artisan apertura:cargar-estudio --dry-run                # preview sin escribir
 *   php artisan apertura:cargar-estudio --confirm                # carga real
 *   php artisan apertura:cargar-estudio --confirm --rollback     # deshace carga previa
 *
 * El JSON con los datos vive en database/seeders/data/apertura_estudio.json.
 */
class CargarAperturaEstudio extends Command
{
    protected $signature = 'apertura:cargar-estudio
        {--dry-run : Simula sin escribir nada (rollback al final)}
        {--confirm : Ejecuta la carga real}
        {--rollback : Deshace la carga previa (requiere manifest existente)}
        {--user-id=1 : ID del usuario que ejecuta (para audit)}
        {--json= : Path al JSON (default: database/seeders/data/apertura_estudio.json)}';

    protected $description = 'v1.43 — Carga programática y atómica de apertura contable del estudio.';

    public function handle(AperturaEstudioImporter $importer): int
    {
        $userId = (int) $this->option('user-id');
        $jsonPath = $this->option('json') ?: database_path('seeders/data/apertura_estudio.json');

        if ($this->option('rollback')) {
            if (! $this->confirmAction('Vas a borrar todo lo cargado por la apertura v1.43 (asientos, bienes, préstamos, auxiliares creados). ¿Continuar?')) {
                $this->warn('Cancelado.');
                return Command::FAILURE;
            }
            $r = $importer->rollback();
            $this->info('Rollback OK. Borrados:');
            $this->line(json_encode($r['borrados'], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if (! $this->option('dry-run') && ! $this->option('confirm')) {
            $this->error('Especificar --dry-run o --confirm (o --rollback).');
            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Cargando apertura desde ' . $jsonPath);

        if (! $dryRun) {
            if (! $this->confirmAction('Vas a impactar la base con la apertura del estudio. Asegurate de tener BACKUP. ¿Continuar?')) {
                $this->warn('Cancelado.');
                return Command::FAILURE;
            }
        }

        try {
            $r = $importer->run($jsonPath, $userId, $dryRun);
            $this->info(($dryRun ? '[DRY-RUN OK] ' : 'Carga OK. ') . 'Stats:');
            $this->line(json_encode($r['stats'], JSON_PRETTY_PRINT));
            if ($dryRun) {
                $this->newLine();
                $this->comment('Dry-run completado. Ningún dato fue persistido. Re-ejecutar con --confirm cuando esté OK.');
            } else {
                $this->newLine();
                $this->info('Manifest guardado en storage/app/apertura_estudio_v143_manifest.json (úsalo con --rollback si hace falta).');
            }
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('FALLA: ' . $e->getMessage());
            $this->line('Trace:');
            $this->line($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function confirmAction(string $msg): bool
    {
        if (! $this->input->isInteractive()) return true;
        return $this->confirm($msg, false);
    }
}
