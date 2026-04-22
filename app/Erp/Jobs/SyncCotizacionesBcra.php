<?php

namespace App\Erp\Jobs;

use App\Erp\Models\Cotizacion;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza cotizaciones oficiales del BCRA (SPEC_01 §5.1, §7.4).
 *
 * Usa la API pública de Estadísticas Cambiarias BCRA:
 *   GET https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones
 *
 * Para cada moneda presente en erp_monedas con cotizable=1 persiste una fila
 * en erp_cotizaciones con tipo=OFICIAL y valor_referencia = valor de la API.
 * Es idempotente por (empresa_id, moneda_id, fecha, tipo) via unique key.
 */
class SyncCotizacionesBcra implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 3;

    public function __construct(public readonly ?string $fecha = null) {}

    public function uniqueId(): string
    {
        return 'sync_bcra_'.($this->fecha ?? 'hoy');
    }

    public function handle(): void
    {
        $fecha = $this->fecha ? Carbon::parse($this->fecha) : Carbon::today();

        $response = Http::timeout(15)
            ->withoutVerifying() // BCRA usa cert propio que puede no validar en todos los hosts
            ->get('https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones', [
                'fecha' => $fecha->toDateString(),
            ]);

        if (! $response->successful()) {
            Log::warning('SyncCotizacionesBcra falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $this->fail(new \RuntimeException('BCRA respondió '.$response->status()));

            return;
        }

        $detalles = $response->json('results.detalle') ?? [];
        $monedas = Moneda::where('activa', true)->get()->keyBy('codigo');
        $empresas = Empresa::pluck('id');

        foreach ($detalles as $d) {
            $codigo = $d['codigoMoneda'] ?? null;
            $valor = $d['tipoCotizacion'] ?? null;
            if (! $codigo || $valor === null) {
                continue;
            }

            $moneda = $monedas->get($codigo);
            if (! $moneda) {
                continue;
            }

            foreach ($empresas as $empresaId) {
                Cotizacion::updateOrCreate(
                    [
                        'empresa_id' => $empresaId,
                        'moneda_id' => $moneda->id,
                        'fecha' => $fecha->toDateString(),
                        'tipo' => 'OFICIAL',
                    ],
                    [
                        'valor_compra' => $valor,
                        'valor_venta' => $valor,
                        'valor_referencia' => $valor,
                        'fuente' => 'BCRA',
                    ]
                );
            }
        }
    }
}
