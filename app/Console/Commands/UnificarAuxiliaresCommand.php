<?php

namespace App\Console\Commands;

use App\Erp\Services\Auxiliares\MergeAuxiliaresService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v1.36 — Unifica auxiliares duplicados por CUIT.
 *
 *   php artisan auxiliares:unificar-duplicados                       (dry-run, CSV preview)
 *   php artisan auxiliares:unificar-duplicados --confirm             (ejecuta — requiere dry-run < 24h)
 *   php artisan auxiliares:unificar-duplicados --confirm --excluir=30717024393,...
 *   php artisan auxiliares:unificar-duplicados --tipo=Proveedor
 *
 * Alcance default: tipo=Cliente. Excluye CUITs placeholder.
 */
class UnificarAuxiliaresCommand extends Command
{
    protected $signature = 'auxiliares:unificar-duplicados
        {--confirm : Ejecuta el merge (sin esta flag es dry-run)}
        {--tipo=Cliente : Tipo de auxiliar a deduplicar}
        {--excluir= : CUITs a excluir, separados por coma}';

    protected $description = 'Unifica auxiliares duplicados por CUIT (merge + reasignación de FKs)';

    private const CACHE_KEY = 'auxiliares_merge_dryrun_ok';

    public function handle(MergeAuxiliaresService $svc): int
    {
        $tipo = (string) $this->option('tipo');
        $excluir = array_filter(array_map('trim', explode(',', (string) $this->option('excluir'))));
        $confirm = (bool) $this->option('confirm');

        $grupos = $svc->detectarGrupos($tipo, $excluir);
        if (empty($grupos)) {
            $this->info("No hay duplicados de tipo {$tipo} (excluyendo placeholders).");
            return self::SUCCESS;
        }

        $this->info(sprintf('%d grupos de %s duplicados detectados.', count($grupos), $tipo));

        // Preview por grupo.
        $rows = [];
        foreach ($grupos as $g) {
            $canonico = $svc->elegirCanonico($g['ids']);
            $perdedores = array_values(array_diff($g['ids'], [$canonico]));
            $fks = $svc->contarFksAReasignar($perdedores);
            $alerta = $this->detectarAlerta($g);
            $rows[] = [
                'cuit' => $g['cuit'],
                'ids' => implode(',', $g['ids']),
                'codigos' => implode(' | ', $g['codigos']),
                'canonico' => $canonico . ' (' . ($g['codigos'][$canonico] ?? '?') . ')',
                'perdedores' => implode(',', $perdedores),
                'fks' => array_sum($fks) . ' (' . collect($fks)->map(fn ($v, $k) => "{$k}={$v}")->implode('; ') . ')',
                'alerta' => $alerta,
            ];
        }

        $this->table(['CUIT', 'IDs', 'Códigos', 'Canónico', 'Perdedores', 'FKs a reasignar', 'Alerta'],
            array_map(fn ($r) => [$r['cuit'], $r['ids'], $r['codigos'], $r['canonico'], $r['perdedores'], $r['fks'], $r['alerta']], $rows));

        if (! $confirm) {
            // Exportar CSV + marcar dry-run.
            $csv = "cuit,ids,codigos,canonico,perdedores,fks_a_reasignar,alerta\n";
            foreach ($rows as $r) {
                $csv .= sprintf("%s,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                    $r['cuit'], $r['ids'], $r['codigos'], $r['canonico'], $r['perdedores'], $r['fks'], $r['alerta']);
            }
            $path = 'auxiliares_merge_preview_' . now()->format('Ymd_His') . '.csv';
            Storage::disk('local')->put($path, $csv);
            Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addHours(24));
            $this->newLine();
            $this->info("🔍 DRY-RUN. Nada fue modificado.");
            $this->line("CSV: storage/app/{$path}");
            $this->line('Para ejecutar: php artisan auxiliares:unificar-duplicados --confirm');
            return self::SUCCESS;
        }

        // --confirm: requiere dry-run reciente.
        if (! Cache::has(self::CACHE_KEY)) {
            $this->error('Falta dry-run reciente (< 24h). Corré primero sin --confirm.');
            return self::FAILURE;
        }
        if (! $this->confirm('¿Ejecutar el merge REAL sobre ' . count($grupos) . ' grupos? Es irreversible.')) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        // Backup de erp_auxiliares.
        $this->backup();

        $okGrupos = 0; $totalMergeados = 0; $errores = [];
        foreach ($grupos as $g) {
            try {
                $r = $svc->ejecutarMerge($g['ids'], $g['cuit'],
                    "Comando v1.36 unificar-duplicados (tipo {$tipo})", null);
                $okGrupos++;
                $totalMergeados += count($r['mergeados_ids']);
            } catch (\Throwable $e) {
                $errores[] = "{$g['cuit']}: {$e->getMessage()}";
            }
        }

        // Validar invariante.
        $restantes = $svc->detectarGrupos($tipo, $excluir);
        $this->newLine();
        $this->info("✅ Grupos mergeados: {$okGrupos} · Auxiliares eliminados: {$totalMergeados}");
        if (! empty($errores)) {
            $this->error(count($errores) . ' errores:');
            foreach ($errores as $e) $this->line("  - {$e}");
        }
        if (! empty($restantes)) {
            $this->warn(count($restantes) . ' grupos duplicados restantes (revisar — pueden ser excluidos/edge).');
        } else {
            $this->info('Invariante OK: 0 duplicados restantes.');
        }
        Cache::forget(self::CACHE_KEY);
        return self::SUCCESS;
    }

    private function detectarAlerta(array $g): string
    {
        // Razón social divergente entre los nombres del grupo.
        $nombres = array_values($g['nombres'] ?? []);
        if (count($nombres) >= 2) {
            $a = strtolower(preg_replace('/[^a-z0-9]/i', '', $nombres[0]));
            $b = strtolower(preg_replace('/[^a-z0-9]/i', '', $nombres[1]));
            similar_text($a, $b, $pct);
            if ($pct < 50) return 'RAZON_SOCIAL_DIVERGENTE';
        }
        return '';
    }

    private function backup(): void
    {
        $dir = storage_path('backups');
        if (! is_dir($dir)) mkdir($dir, 0775, true);
        $file = $dir . '/erp_auxiliares_' . now()->format('Ymd_His') . '.sql';
        $db = config('database.connections.' . config('database.default'));
        $cmd = sprintf(
            'mysqldump -h%s -P%s -u%s %s %s erp_auxiliares erp_centros_costo > %s 2>/dev/null',
            escapeshellarg($db['host']), escapeshellarg((string) $db['port']),
            escapeshellarg($db['username']),
            $db['password'] ? '-p' . escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']), escapeshellarg($file),
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || ! file_exists($file) || filesize($file) === 0) {
            throw new \RuntimeException('Backup de erp_auxiliares falló — abortando merge.');
        }
        $this->line("Backup: {$file} (" . round(filesize($file) / 1024, 1) . ' KB)');
    }
}
