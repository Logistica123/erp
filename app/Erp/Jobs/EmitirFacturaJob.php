<?php

namespace App\Erp\Jobs;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Models\VentasCompras\FacturaVentaCae;
use App\Erp\Services\ArcaGatewayClient;
use App\Erp\Support\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Outbox worker de emisión WSFE (SPEC 03 RN-39).
 *
 * Toma filas PENDIENTES de erp_factura_venta_emision_queue con
 * FOR UPDATE SKIP LOCKED (paraleliza seguro entre workers), las marca
 * EN_VUELO, llama a arca-gateway y persiste el resultado.
 *
 * Garantiza exactly-once mediante idempotency_key determinista por
 * factura (el gateway devuelve el CAE previo si ya se emitió).
 *
 * Backoff exponencial: 1 min / 5 min / 15 min (3 intentos max).
 */
class EmitirFacturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1; // el retry lo maneja el outbox, no Laravel Queue

    public function __construct(public readonly ?int $facturaId = null) {}

    public function handle(ArcaGatewayClient $gateway, AuditLogger $audit): void
    {
        // Selecciona una fila de la cola con lock (o la factura específica si se pasó).
        $queueRow = DB::table('erp_factura_venta_emision_queue')
            ->where('estado', 'PENDIENTE')
            ->where('proximo_intento_at', '<=', now())
            ->when($this->facturaId, fn ($q, $id) => $q->where('factura_venta_id', $id))
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $queueRow) {
            return;
        }

        DB::table('erp_factura_venta_emision_queue')
            ->where('id', $queueRow->id)
            ->update([
                'estado' => 'EN_VUELO',
                'locked_at' => now(),
                'intento_actual' => ($queueRow->intento_actual ?? 0) + 1,
                'updated_at' => now(),
            ]);

        try {
            $factura = FacturaVenta::with(['items', 'iva', 'tributos', 'puntoVenta', 'asociadas'])->findOrFail($queueRow->factura_venta_id);
            $payload = $this->buildPayload($factura);

            $response = $gateway->emitir($payload);
            if (! $response->ok()) {
                $this->aplicarReintento($queueRow, 'gateway status '.$response->status());

                return;
            }

            $data = $response->json();
            if (($data['resultado'] ?? null) === 'A' && ! empty($data['cae'])) {
                DB::transaction(function () use ($factura, $data, $queueRow) {
                    $factura->update([
                        'cae' => $data['cae'],
                        'fecha_vto_cae' => $data['cae_vto'],
                        'estado' => 'EMITIDA',
                    ]);
                    FacturaVentaCae::updateOrCreate(
                        ['factura_venta_id' => $factura->id],
                        [
                            'cae' => $data['cae'],
                            'fecha_vto_cae' => $data['cae_vto'],
                            'resultado' => 'A',
                            'observaciones_afip' => $data['observaciones'] ?? null,
                            'errores_afip' => $data['errores'] ?? null,
                            'idempotency_key' => $data['idempotency_key'] ?? $payload['idempotency_key'],
                            'emitida_at' => now(),
                        ]
                    );
                    DB::table('erp_factura_venta_emision_queue')->where('id', $queueRow->id)->update([
                        'estado' => 'OK',
                        'updated_at' => now(),
                    ]);
                });
            } else {
                // Error de negocio de AFIP: no reintento
                $factura->update(['estado' => 'EMISION_FALLIDA']);
                DB::table('erp_factura_venta_emision_queue')->where('id', $queueRow->id)->update([
                    'estado' => 'ERROR',
                    'ultimo_error' => json_encode($data['errores'] ?? $data),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->aplicarReintento($queueRow, $e->getMessage());
        }
    }

    private function aplicarReintento(object $queueRow, string $error): void
    {
        $intento = ($queueRow->intento_actual ?? 0);
        $max = $queueRow->max_intentos ?? 3;
        $delays = [60, 300, 900]; // 1m, 5m, 15m

        if ($intento >= $max) {
            DB::table('erp_factura_venta_emision_queue')->where('id', $queueRow->id)->update([
                'estado' => 'ERROR',
                'ultimo_error' => substr($error, 0, 500),
                'updated_at' => now(),
            ]);
            FacturaVenta::where('id', $queueRow->factura_venta_id)
                ->update(['estado' => 'EMISION_FALLIDA']);

            return;
        }

        $proximo = now()->addSeconds($delays[min($intento, count($delays) - 1)]);
        DB::table('erp_factura_venta_emision_queue')->where('id', $queueRow->id)->update([
            'estado' => 'PENDIENTE',
            'proximo_intento_at' => $proximo,
            'ultimo_error' => substr($error, 0, 500),
            'updated_at' => now(),
        ]);
    }

    private function buildPayload(FacturaVenta $f): array
    {
        $idempotencyKey = 'fv-'.$f->id.'-'.substr(md5($f->updated_at?->toIso8601String() ?? (string) $f->id), 0, 12);

        return [
            'idempotency_key' => $idempotencyKey,
            'tipo_cbte' => $f->tipo_comprobante_id,
            'pto_vta' => $f->puntoVenta->numero,
            'concepto' => $f->concepto_afip,
            'doc_tipo' => $f->doc_tipo_afip ?? 99,
            'doc_nro' => $f->doc_nro ?? '0',
            'condicion_iva_receptor_id' => $f->condicion_iva_id,
            'cbte_desde' => $f->numero,
            'cbte_hasta' => $f->numero,
            'fecha_cbte' => $f->fecha_emision->format('Y-m-d'),
            'fecha_venc_pago' => $f->fecha_vencimiento?->format('Y-m-d'),
            'mon_id' => 'PES',
            'mon_cotiz' => (string) $f->cotizacion,
            'imp_total' => (string) $f->imp_total,
            'imp_tot_conc' => (string) $f->imp_no_gravado,
            'imp_neto' => (string) $f->imp_neto_gravado,
            'imp_op_ex' => (string) $f->imp_exento,
            'imp_iva' => (string) $f->imp_iva,
            'imp_trib' => (string) $f->imp_tributos,
            'iva' => $f->iva->map(fn ($r) => [
                'id' => $r->alicuota_iva_id,
                'base_imp' => (string) $r->base_imponible,
                'importe' => (string) $r->importe_iva,
            ])->all(),
            'tributos' => $f->tributos->map(fn ($t) => [
                'id' => $t->tributo_id,
                'descripcion' => $t->descripcion,
                'base_imp' => (string) $t->base_imponible,
                'alic' => (string) $t->alicuota,
                'importe' => (string) $t->importe,
            ])->all(),
        ];
    }
}
