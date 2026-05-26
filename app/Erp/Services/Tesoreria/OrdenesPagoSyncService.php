<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Models\Tesoreria\OrdenPagoAudit;
use App\Erp\Models\Tesoreria\OrdenPagoTipo;
use App\Erp\Services\Integracion\DistriAppBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v1.35 — Sync de Órdenes de Pago desde DistriApp (basepersonal.liq_ordenes_pago).
 *
 * Política (D-35-2, D-35-8): read-only del lado DistriApp. Las OP sincronizadas
 * NO generan asiento automático — quedan "listas para contabilizar". Si una OP
 * ya está contabilizada y DistriApp la cambia, se BLOQUEA la actualización de
 * campos contables (solo se refrescan los informativos) y se loguea discrepancia.
 */
class OrdenesPagoSyncService
{
    private const CACHE_KEY_ULTIMA_SYNC = 'op_sync_ultima_exitosa';

    public function __construct(
        private readonly DistriAppBridge $bridge,
    ) {}

    public function backfillCompleto(bool $dryRun = false): array
    {
        $creadas = 0; $actualizadas = 0; $sinCambios = 0; $errores = [];
        $offset = 0; $batchSize = 200;

        do {
            $batch = $this->bridge->fetchOrdenesPago(['offset' => $offset, 'limit' => $batchSize]);
            $filas = $batch['data'];
            if (empty($filas)) break;

            foreach ($filas as $op) {
                try {
                    if ($dryRun) { $creadas++; continue; }
                    $r = $this->upsertDesdeDistriapp((array) $op);
                    match ($r) {
                        'creada' => $creadas++,
                        'actualizada' => $actualizadas++,
                        default => $sinCambios++,
                    };
                } catch (\Throwable $e) {
                    $errores[] = ['distriapp_op_id' => $op->id ?? null, 'error' => $e->getMessage()];
                    Log::error('Sync OP backfill error', ['op_id' => $op->id ?? null, 'msg' => $e->getMessage()]);
                }
            }
            $offset += $batchSize;
        } while (count($filas) === $batchSize);

        if (! $dryRun) {
            Cache::put(self::CACHE_KEY_ULTIMA_SYNC, now(), now()->addDays(30));
        }

        return compact('creadas', 'actualizadas', 'sinCambios', 'errores');
    }

    public function syncIncremental(): array
    {
        $ultima = Cache::get(self::CACHE_KEY_ULTIMA_SYNC) ?? Carbon::createFromDate(2020, 1, 1);
        $creadas = 0; $actualizadas = 0; $sinCambios = 0; $errores = [];

        $batch = $this->bridge->fetchOrdenesPago([
            'updated_desde' => Carbon::parse($ultima)->toDateTimeString(),
            'limit' => 500,
        ]);

        foreach ($batch['data'] as $op) {
            try {
                $r = $this->upsertDesdeDistriapp((array) $op);
                match ($r) {
                    'creada' => $creadas++,
                    'actualizada' => $actualizadas++,
                    default => $sinCambios++,
                };
            } catch (\Throwable $e) {
                $errores[] = ['distriapp_op_id' => $op->id ?? null, 'error' => $e->getMessage()];
            }
        }

        Cache::put(self::CACHE_KEY_ULTIMA_SYNC, now(), now()->addDays(30));

        return compact('creadas', 'actualizadas', 'sinCambios', 'errores');
    }

    private function upsertDesdeDistriapp(array $op, int $empresaId = 1): string
    {
        $hash = hash('sha256', json_encode($op));
        $existente = OrdenPago::where('distriapp_op_id', $op['id'])->first();

        if ($existente && $existente->sync_hash === $hash) {
            return 'sin_cambios';
        }

        // D-35-8: bloqueo si ya está contabilizada — solo refrescar informativos.
        if ($existente && $existente->contabilizada) {
            return $this->actualizarSeguraContabilizada($existente, $op, $hash);
        }

        $beneficiarioId = $this->resolverBeneficiarioId(
            (string) ($op['beneficiario_cuil'] ?? ''),
            (string) ($op['beneficiario_nombre'] ?? ''),
            (string) ($op['beneficiario_tipo'] ?? 'DISTRIBUIDOR'),
            $empresaId,
        );

        $tipoDistId = OrdenPagoTipo::where('empresa_id', $empresaId)->where('codigo', 'DIST')->value('id');
        $monedaId = DB::table('erp_monedas')->where('codigo', 'ARS')->value('id') ?? 1;
        $totalAPagar = (float) ($op['total_a_pagar'] ?? 0);
        $subtotal = (float) ($op['subtotal'] ?? $totalAPagar);
        $descuentos = (float) ($op['total_descuentos'] ?? 0);

        $payload = [
            'empresa_id' => $empresaId,
            'origen' => OrdenPago::ORIGEN_DISTRIAPP,
            'distriapp_op_id' => $op['id'],
            'distriapp_concepto_id' => $op['concepto_id'] ?? null,
            'distriapp_numero_correlativo' => $op['numero_display'] ?? null,
            'numero' => $existente?->numero ?? $this->siguienteNumeroErp($empresaId),
            'fecha' => $op['fecha_emision'] ?? now()->toDateString(),
            'tipo' => 'DISTRIBUIDOR',
            'tipo_op_id' => $tipoDistId,
            'auxiliar_id' => $beneficiarioId,
            'beneficiario_snapshot' => [
                'tipo' => $op['beneficiario_tipo'] ?? null,
                'nombre' => $op['beneficiario_nombre'] ?? null,
                'cuil' => $op['beneficiario_cuil'] ?? null,
                'cbu' => $op['beneficiario_cbu'] ?? null,
            ],
            'moneda_id' => $monedaId,
            'cotizacion' => 1.0,
            'importe' => $totalAPagar,
            'importe_bruto' => $subtotal,
            'total_retenciones' => $descuentos,
            'concepto' => $op['concepto_nombre'] ?? ($op['observaciones'] ?? 'OP DistriApp'),
            'observaciones' => $op['observaciones'] ?? null,
            'estado' => $this->mapearEstado((string) ($op['estado'] ?? 'BORRADOR')),
            'fecha_pago' => $op['icbc_acreditado_at'] ?? null,
            'medio_pago' => $op['medio_pago'] ?? null,
            'referencia_pago' => $op['icbc_tx_id'] ?? null,
            'creado_por_user_id' => $existente?->creado_por_user_id ?? 1,
            'sync_ultima_actualizacion' => now(),
            'sync_hash' => $hash,
            'sync_payload_completo' => $op,
        ];

        if ($existente) {
            $antes = $existente->toArray();
            $existente->update($payload);
            $this->audit($existente->id, 'SYNC_UPDATE', null, $antes, $payload);
            return 'actualizada';
        }

        $nueva = OrdenPago::create($payload);
        $this->audit($nueva->id, 'SYNC_UPDATE', null, null, $payload);
        return 'creada';
    }

    private function actualizarSeguraContabilizada(OrdenPago $op, array $data, string $hash): string
    {
        $importeDistri = (float) ($data['total_a_pagar'] ?? 0);
        $cambioImporte = abs((float) $op->importe - $importeDistri) > 0.01;

        // Solo refrescar campos informativos, NO contables.
        $op->update([
            'estado' => $this->mapearEstado((string) ($data['estado'] ?? $op->estado)),
            'fecha_pago' => $data['icbc_acreditado_at'] ?? $op->fecha_pago,
            'referencia_pago' => $data['icbc_tx_id'] ?? $op->referencia_pago,
            'sync_ultima_actualizacion' => now(),
            'sync_hash' => $hash,
            'sync_payload_completo' => $data,
        ]);

        $motivo = $cambioImporte
            ? sprintf('OP contabilizada. DistriApp cambió importe %.2f→%.2f — NO se actualizó el importe local.',
                (float) $op->importe, $importeDistri)
            : 'OP contabilizada — solo se refrescaron campos informativos.';
        $this->audit($op->id, 'SYNC_UPDATE_BLOQUEADO', null, null, $data, $motivo);
        if ($cambioImporte) {
            Log::warning('Sync OP bloqueado por contabilizada', ['op_id' => $op->id, 'motivo' => $motivo]);
        }
        return 'actualizada';
    }

    private function resolverBeneficiarioId(string $cuil, string $nombre, string $tipoDistri, int $empresaId): int
    {
        $cuitLimpio = preg_replace('/[^0-9]/', '', $cuil);
        if (strlen((string) $cuitLimpio) === 11) {
            $aux = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('cuit', $cuitLimpio)
                ->orderByRaw("FIELD(tipo,'Distribuidor','Proveedor','Empleado','Socio') ")
                ->first(['id']);
            if ($aux) return (int) $aux->id;
        }

        // No existe → crear auxiliar. DISTRIBUIDOR→Distribuidor, otros→Proveedor.
        $tipoErp = strtoupper($tipoDistri) === 'DISTRIBUIDOR' ? 'Distribuidor' : 'Proveedor';
        $cuentaDefault = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', '2.1.1.01')->value('id');
        return (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo' => $tipoErp,
            'codigo' => 'OP-' . ($cuitLimpio ?: substr(md5($nombre), 0, 8)),
            'nombre' => $nombre ?: ('Beneficiario ' . $cuil),
            'cuit' => strlen((string) $cuitLimpio) === 11 ? $cuitLimpio : null,
            'cuenta_contable_default_id' => $cuentaDefault,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mapearEstado(string $estadoDistri): string
    {
        return match (strtoupper($estadoDistri)) {
            'BORRADOR' => OrdenPago::ESTADO_BORRADOR,
            'PENDIENTE_PAGO', 'ENVIADA_BANCO', 'PENDIENTE', 'GENERADA', 'APROBADA', 'AUTORIZADA' => OrdenPago::ESTADO_EMITIDA,
            'CONFIRMADA', 'PAGADA', 'TRANSFERIDA', 'COMPLETADA' => OrdenPago::ESTADO_PAGADA,
            'RECHAZADA' => OrdenPago::ESTADO_RECHAZADA,
            'ANULADA', 'CANCELADA' => OrdenPago::ESTADO_ANULADA,
            default => OrdenPago::ESTADO_EMITIDA,
        };
    }

    private function siguienteNumeroErp(int $empresaId): string
    {
        $anio = (int) date('Y');
        return DB::transaction(function () use ($empresaId, $anio) {
            $sec = DB::table('erp_secuencias_op')
                ->where('empresa_id', $empresaId)->where('anio', $anio)
                ->lockForUpdate()->first();
            if (! $sec) {
                DB::table('erp_secuencias_op')->insert([
                    'empresa_id' => $empresaId, 'anio' => $anio, 'ultimo_numero' => 0,
                ]);
                $ultimo = 0;
            } else {
                $ultimo = (int) $sec->ultimo_numero;
            }
            $proximo = $ultimo + 1;
            DB::table('erp_secuencias_op')
                ->where('empresa_id', $empresaId)->where('anio', $anio)
                ->update(['ultimo_numero' => $proximo]);
            return sprintf('OP-%d-%06d', $anio, $proximo);
        });
    }

    private function audit(int $opId, string $accion, ?int $userId, ?array $antes, array $despues, ?string $motivo = null): void
    {
        OrdenPagoAudit::create([
            'op_id' => $opId,
            'accion' => $accion,
            'user_id' => $userId,
            'snapshot_antes' => $antes,
            'snapshot_despues' => $despues,
            'motivo' => $motivo,
            'created_at' => now(),
        ]);
    }
}
