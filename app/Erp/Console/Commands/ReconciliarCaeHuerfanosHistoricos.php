<?php

namespace App\Erp\Console\Commands;

use App\Erp\Services\ArcaGatewayClient;
use App\Erp\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Q-12 (pack 2026-07-13) — reconciliación HISTÓRICA de CAEs huérfanos.
 *
 * El escenario (auditoría 12/07, corregido hacia adelante ese día): AFIP
 * autorizó un CAE pero el ERP no lo guardó (corte de red post-emisión).
 * Este comando barre el pasado: por cada (tipo AFIP, punto de venta) usado
 * desde --desde, consulta FECompUltimoAutorizado y compara con el MAX
 * local — todo número que AFIP tiene y el ERP no, es un huérfano.
 *
 * SOLO LECTURA contra AFIP (no emite nada). Sin --confirm es dry-run puro;
 * con --confirm deja constancia en el audit log de cada huérfano.
 */
class ReconciliarCaeHuerfanosHistoricos extends Command
{
    protected $signature = 'cae:reconciliar-huerfanos-historicos
        {--desde=2026-01-01 : Fecha de emisión mínima a considerar}
        {--confirm : Registrar los huérfanos en el audit log (default: dry-run)}';

    protected $description = 'Compara FECompUltimoAutorizado de AFIP contra el MAX local por (tipo, PV) y lista CAEs huérfanos';

    public function __construct(
        private readonly ArcaGatewayClient $gateway,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $desde = (string) $this->option('desde');
        $dryRun = ! $this->option('confirm');
        $this->info(($dryRun ? '[DRY-RUN] ' : '').'Reconciliación de CAEs huérfanos desde '.$desde);

        // Pares (tipo AFIP, PV) realmente usados en emisión electrónica.
        $pares = DB::table('erp_facturas_venta as fv')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
            ->whereNotNull('fv.cae')
            ->where('fv.fecha_emision', '>=', $desde)
            ->whereNull('fv.deleted_at')
            ->groupBy('fv.tipo_comprobante_id', 'pv.numero')
            ->selectRaw('fv.tipo_comprobante_id tipo, pv.numero pv_numero, MAX(fv.numero) max_local, COUNT(*) emitidas')
            ->get();

        if ($pares->isEmpty()) {
            $this->info('No hay emisiones electrónicas desde '.$desde.' — nada que reconciliar.');

            return self::SUCCESS;
        }

        $totalHuerfanos = 0;
        $errores = 0;

        foreach ($pares as $par) {
            $etiqueta = sprintf('tipo %d / PV %04d', $par->tipo, $par->pv_numero);
            try {
                $resp = $this->gateway->ultimoAutorizado((int) $par->tipo, (int) $par->pv_numero);
                if (! $resp->successful()) {
                    $this->error("  {$etiqueta}: gateway respondió {$resp->status()} — no verificable.");
                    $errores++;

                    continue;
                }
                $ultimoAfip = (int) ($resp->json('cbte_nro') ?? 0);
            } catch (\Throwable $e) {
                $this->error("  {$etiqueta}: error consultando AFIP — ".$e->getMessage());
                $errores++;

                continue;
            }

            if ($ultimoAfip <= (int) $par->max_local) {
                $this->line("  {$etiqueta}: OK — AFIP {$ultimoAfip} vs local {$par->max_local} ({$par->emitidas} emitidas).");

                continue;
            }

            // Gap: números que AFIP autorizó y el ERP no registró.
            $faltantes = range((int) $par->max_local + 1, $ultimoAfip);
            $totalHuerfanos += count($faltantes);
            $this->warn(sprintf('  %s: %d HUÉRFANO(S) — AFIP llegó a %d, ERP a %d. Números: %s',
                $etiqueta, count($faltantes), $ultimoAfip, $par->max_local,
                implode(', ', array_slice($faltantes, 0, 20)).(count($faltantes) > 20 ? '…' : '')));

            // Detalle de cada huérfano (importe/fecha) vía FECompConsultar.
            foreach (array_slice($faltantes, 0, 20) as $nro) {
                try {
                    $det = $this->gateway->consultar((int) $par->tipo, (int) $par->pv_numero, $nro);
                    if ($det->successful()) {
                        $this->line(sprintf('      #%d: fecha %s, importe %s, CAE %s',
                            $nro, $det->json('cbte_fch') ?? '?', $det->json('imp_total') ?? '?', $det->json('cae') ?? '?'));
                    }
                } catch (\Throwable) {
                    // detalle best-effort, el hallazgo ya está reportado
                }
            }

            if (! $dryRun) {
                $this->audit->logEvento(
                    accion: 'CAE_HUERFANO_DETECTADO',
                    modulo: 'ventas',
                    descripcion: sprintf('Reconciliación histórica: %s — AFIP %d vs local %d (%d huérfanos: %s)',
                        $etiqueta, $ultimoAfip, $par->max_local, count($faltantes), implode(',', $faltantes)),
                    empresaId: 1,
                );
            }
        }

        $this->newLine();
        $this->info(sprintf('Resultado: %d huérfano(s) en %d par(es) tipo/PV%s.',
            $totalHuerfanos, $pares->count(), $errores ? " ({$errores} pares no verificables)" : ''));
        if ($dryRun && $totalHuerfanos > 0) {
            $this->comment('Dry-run: nada registrado. Repetir con --confirm para dejar constancia en el audit log.');
        }

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
