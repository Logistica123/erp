<?php

namespace App\Erp\Jobs;

use App\Erp\Services\MisComprobantesService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Cron diario 02:00 — importa Mis Comprobantes del día anterior (SPEC 03 RN-43).
 * uniqueId evita runs concurrentes sobre el mismo día.
 */
class ImportarMisComprobantesDiario implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public readonly ?string $desde = null,
        public readonly ?string $hasta = null,
        public readonly int $empresaId = 1,
    ) {}

    public function uniqueId(): string
    {
        return 'mis-comp-'.($this->desde ?? Carbon::yesterday()->toDateString());
    }

    public function handle(MisComprobantesService $service): void
    {
        $sistema = User::firstOrCreate(
            ['email' => 'cron@erp.local'],
            ['name' => 'ERP Cron', 'password' => bcrypt(bin2hex(random_bytes(16)))]
        );

        $desde = $this->desde ?? Carbon::yesterday()->toDateString();
        $hasta = $this->hasta ?? Carbon::yesterday()->toDateString();

        $service->ejecutar($desde, $hasta, $sistema, $this->empresaId);
    }
}
