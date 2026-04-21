<?php

namespace App\Erp\Jobs;

use App\Erp\Services\SaldosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recompone los saldos de un período completo. Se encola `ShouldBeUnique`
 * para evitar que dos jobs simultáneos pisen la tabla `erp_saldos_cuenta`
 * del mismo período.
 */
class RecomputarSaldosPeriodo implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(public readonly int $periodoId) {}

    public function uniqueId(): string
    {
        return 'recomputar_saldos_periodo_'.$this->periodoId;
    }

    public function handle(SaldosService $service): void
    {
        $service->recomputarPeriodo($this->periodoId);
    }
}
