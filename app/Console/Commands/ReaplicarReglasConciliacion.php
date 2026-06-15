<?php

namespace App\Console\Commands;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Conciliacion\MatchingAutoService;
use App\Erp\Services\Tesoreria\MatchingContraparteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v1.47.1 — Re-aplica las reglas de conciliación a movimientos YA importados
 * que quedaron sin cuenta propuesta (PENDIENTE o ETIQUETADO sin
 * cuenta_contable_propuesta_id), sin necesidad de re-importar el extracto.
 * Útil tras ajustar regex de reglas (Bug #1).
 */
class ReaplicarReglasConciliacion extends Command
{
    protected $signature = 'tesoreria:reaplicar-reglas
        {--cuenta= : Limitar a una cuenta bancaria (id)}
        {--dry-run : Sólo reportar, no escribir}';

    protected $description = 'Re-aplica reglas de conciliación a movimientos sin cuenta propuesta (post ajuste de regex).';

    public function handle(MatchingContraparteService $matcher, MatchingAutoService $matchingAuto): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = MovimientoBancario::query()
            ->with('cuentaBancaria')
            ->where(function ($w) {
                $w->where('estado', 'PENDIENTE')
                  ->orWhere(fn ($x) => $x->where('estado', 'ETIQUETADO')->whereNull('cuenta_contable_propuesta_id'));
            });
        if ($c = $this->option('cuenta')) $q->where('cuenta_bancaria_id', (int) $c);
        $movs = $q->get();

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Evaluando {$movs->count()} movimientos…");
        $etiquetados = 0; $matchAuto = 0;

        foreach ($movs as $mov) {
            // 1) Pasada de reglas (asigna cuenta_contable_propuesta).
            $r = $matcher->matchear($mov);
            $cuentaProp = $r['cuenta_contable_propuesta_id'] ?? null;
            if (($r['confianza_match'] ?? 0) >= 50 && $cuentaProp) {
                if (! $dry) {
                    $mov->update([
                        'cuenta_contable_propuesta_id' => $cuentaProp,
                        'regla_aplicada_id' => $r['regla_aplicada_id'] ?? $mov->regla_aplicada_id,
                        'confianza_match' => $r['confianza_match'],
                        'estado' => $mov->estado === 'PENDIENTE' ? 'ETIQUETADO' : $mov->estado,
                        'cuit_contraparte' => $r['cuit_contraparte'] ?? $mov->cuit_contraparte,
                        'nombre_contraparte' => $r['nombre_contraparte'] ?? $mov->nombre_contraparte,
                    ]);
                }
                $etiquetados++;
                continue;
            }
            // 2) Matching auto CUIT (sólo PENDIENTE).
            if ($mov->estado === 'PENDIENTE' && ! $dry && $matchingAuto->intentarMatching($mov->fresh())) {
                $matchAuto++;
            }
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Etiquetados con cuenta: {$etiquetados} · MATCH_AUTO: {$matchAuto}");
        return self::SUCCESS;
    }
}
