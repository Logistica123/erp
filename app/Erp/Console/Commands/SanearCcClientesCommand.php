<?php

namespace App\Erp\Console\Commands;

use App\Erp\Support\CcCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mini-tanda 2026-07-13, bug 3 — saneo de datos: clientes activos sin CC
 * (creados por insert crudo antes de que los caminos de integración
 * llamaran a CcCliente::asegurar()).
 *
 * Idempotente: correrlo N veces no duplica nada. En prod: 9 gaps al
 * 2026-07-13 (correr recién con el OK de deploy de la mini-tanda).
 */
class SanearCcClientesCommand extends Command
{
    protected $signature = 'erp:sanear-cc-clientes {--dry-run : Solo listar, sin crear}';

    protected $description = 'Crea el Centro de Costos faltante de los auxiliares Cliente activos que no lo tienen';

    public function handle(): int
    {
        $sinCc = DB::table('erp_auxiliares as a')
            ->leftJoin('erp_centros_costo as cc', 'cc.auxiliar_id', '=', 'a.id')
            ->where('a.tipo', 'Cliente')
            ->where('a.activo', 1)
            ->whereNull('cc.id')
            ->get(['a.id', 'a.codigo', 'a.nombre']);

        if ($sinCc->isEmpty()) {
            $this->info('Sin gaps: todos los clientes activos tienen CC.');

            return self::SUCCESS;
        }

        foreach ($sinCc as $aux) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] #{$aux->id} {$aux->codigo} — {$aux->nombre}");

                continue;
            }
            CcCliente::asegurar((int) $aux->id);
            $this->line("CC creado para #{$aux->id} {$aux->codigo} — {$aux->nombre}");
        }

        $this->info(($this->option('dry-run') ? 'Detectados' : 'Saneados').': '.$sinCc->count());

        return self::SUCCESS;
    }
}
