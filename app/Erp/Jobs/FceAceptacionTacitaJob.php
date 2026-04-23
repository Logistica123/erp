<?php

namespace App\Erp\Jobs;

use App\Erp\Services\FceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cron diario que marca FCE como ACEPTADA_TACITAMENTE al vencer el plazo
 * sin respuesta del cliente (SPEC 03 RN-36).
 */
class FceAceptacionTacitaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(public readonly int $empresaId = 1) {}

    public function uniqueId(): string
    {
        return 'fce-tacita-'.$this->empresaId.'-'.date('Y-m-d');
    }

    public function handle(FceService $service): void
    {
        $service->procesarAceptacionesTacitas($this->empresaId);
    }
}
