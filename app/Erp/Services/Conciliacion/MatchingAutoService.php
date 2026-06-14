<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 §7 — Matching automático con extractor CUIT.
 *
 * Para reglas con `matching_auto_factura = TRUE` y
 * `cuenta_contable_modo = DINAMICO_POR_AUXILIAR`:
 *   1. Extrae el CUIT del concepto con `cuit_extractor_regex`.
 *   2. Identifica al auxiliar (CLIENTE / PROVEEDOR / DISTRIBUIDOR).
 *   3. Busca facturas pendientes del auxiliar y matchea por monto.
 *   4. PROPONE la imputación (estado MATCH_AUTO) — NO genera el asiento.
 *      El asiento se genera al CONFIRMAR (D-45-7: humano siempre revisa).
 *
 * Tolerancia de monto (D-45-5): min($monto*5%, $500).
 */
class MatchingAutoService
{
    private const TOLERANCIA_PCT = 0.05;
    private const TOLERANCIA_MAX = 500.0;

    /** Estados de factura considerados "pendientes de cobro/pago". */
    private const ESTADOS_VENTA = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'];
    private const ESTADOS_COMPRA = ['RECIBIDA', 'CONTROLADA', 'OBSERVADA', 'PAGO_PARCIAL'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Intenta auto-imputar un movimiento usando las reglas con extractor CUIT
     * activas. Devuelve true si lo dejó en MATCH_AUTO.
     */
    public function intentarMatching(MovimientoBancario $mov): bool
    {
        if ($mov->estado !== MovimientoBancario::ESTADO_PENDIENTE) {
            return false;
        }
        $esCredito = (float) $mov->credito > 0.005;
        $signoBuscado = $esCredito ? 'CREDITO' : 'DEBITO';

        $reglas = ConciliacionRegla::query()
            ->where('activa', 1)
            ->where('matching_auto_factura', 1)
            ->whereIn('signo', [$signoBuscado, 'AMBOS'])
            ->orderBy('orden_prioridad')
            ->get();

        foreach ($reglas as $regla) {
            $res = $this->evaluarRegla($mov, $regla);
            if ($res !== null) {
                $this->aplicarResultado($mov, $regla, $res);
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function evaluarRegla(MovimientoBancario $mov, ConciliacionRegla $regla): ?array
    {
        $extractor = $regla->cuit_extractor_regex ?: $regla->patron_concepto;
        if (! $extractor) return null;

        if (! @preg_match("/{$extractor}/iu", (string) $mov->concepto, $m)) {
            return null;
        }
        if (! isset($m[1])) return null; // sin grupo de captura del CUIT.

        $cuit = preg_replace('/[^0-9]/', '', $m[1]);
        if (strlen($cuit) !== 11) return null;

        // Identificar auxiliar por CUIT (tipo de la regla; si PROVEEDOR no
        // matchea, probar DISTRIBUIDOR — D-45 nota distribuidor).
        $tipos = match ($regla->tipo_auxiliar) {
            'CLIENTE' => ['Cliente'],
            'PROVEEDOR' => ['Proveedor', 'Distribuidor'],
            'DISTRIBUIDOR' => ['Distribuidor', 'Proveedor'],
            default => ['Cliente', 'Proveedor', 'Distribuidor'],
        };
        $auxiliar = DB::table('erp_auxiliares')
            ->where('cuit', $cuit)
            ->whereIn('tipo', $tipos)
            ->orderByRaw("FIELD(tipo, '" . implode("','", $tipos) . "')")
            ->first();

        if (! $auxiliar) {
            // CUIT extraído pero sin auxiliar → confianza 0, no auto-imputa.
            return ['cuit' => $cuit, 'auxiliar' => null, 'confianza' => 0,
                    'factura_id' => null, 'factura_tipo' => null, 'tipo_match' => 'SIN_AUXILIAR'];
        }

        $esVenta = (float) $mov->credito > 0.005;
        $facturaTipo = $esVenta ? 'VENTA' : 'COMPRA';
        $monto = (float) max($mov->debito, $mov->credito);

        $facturas = $this->facturasPendientes($auxiliar->id, $esVenta, (int) $mov->cuenta_bancaria_id);

        $match = $this->matchearMonto($monto, $facturas);

        return [
            'cuit' => $cuit,
            'auxiliar' => $auxiliar,
            'confianza' => $match['confianza'],
            'factura_id' => $match['factura_id'],
            'factura_tipo' => $match['factura_id'] ? $facturaTipo : null,
            'tipo_match' => $match['tipo_match'],
        ];
    }

    /**
     * Facturas pendientes del auxiliar con saldo > 0.
     * @return array<int,array{id:int,saldo:float}>
     */
    private function facturasPendientes(int $auxiliarId, bool $esVenta, int $cuentaBancariaId): array
    {
        $empresaId = (int) (DB::table('erp_cuentas_bancarias')->where('id', $cuentaBancariaId)->value('empresa_id') ?: 1);

        if ($esVenta) {
            $rows = DB::table('erp_facturas_venta')
                ->where('empresa_id', $empresaId)
                ->where('auxiliar_id', $auxiliarId)
                ->whereIn('estado', self::ESTADOS_VENTA)
                ->whereNull('deleted_at')
                ->get(['id', 'imp_total']);
            $out = [];
            foreach ($rows as $f) {
                $imputado = (float) DB::table('erp_recibos_comprobantes_imputados')
                    ->where('factura_venta_id', $f->id)->sum('monto_imputado');
                $saldo = round((float) $f->imp_total - $imputado, 2);
                if ($saldo > 0.005) $out[] = ['id' => (int) $f->id, 'saldo' => $saldo];
            }
            return $out;
        }

        // Compra: sin mecanismo unificado de imputación parcial — saldo = imp_total
        // para estados pendientes (mejor esfuerzo; el humano confirma/ajusta).
        $rows = DB::table('erp_facturas_compra')
            ->where('empresa_id', $empresaId)
            ->where('auxiliar_id', $auxiliarId)
            ->whereIn('estado', self::ESTADOS_COMPRA)
            ->whereNull('deleted_at')
            ->get(['id', 'imp_total']);
        $out = [];
        foreach ($rows as $f) {
            $out[] = ['id' => (int) $f->id, 'saldo' => round((float) $f->imp_total, 2)];
        }
        return $out;
    }

    /**
     * @param  array<int,array{id:int,saldo:float}>  $facturas
     * @return array{confianza:int, factura_id:?int, tipo_match:string}
     */
    private function matchearMonto(float $monto, array $facturas): array
    {
        if (empty($facturas)) {
            return ['confianza' => 60, 'factura_id' => null, 'tipo_match' => 'SOLO_CUIT'];
        }

        // Match perfecto: una factura con saldo == monto.
        foreach ($facturas as $f) {
            if (abs($f['saldo'] - $monto) < 0.01) {
                return ['confianza' => 95, 'factura_id' => $f['id'], 'tipo_match' => 'PERFECTO'];
            }
        }

        // Match con tolerancia (retenciones esperables).
        $tol = min($monto * self::TOLERANCIA_PCT, self::TOLERANCIA_MAX);
        foreach ($facturas as $f) {
            if (abs($f['saldo'] - $monto) <= $tol) {
                return ['confianza' => 70, 'factura_id' => $f['id'], 'tipo_match' => 'CON_AJUSTE'];
            }
        }

        // Match compuesto: subset de 2-3 facturas que sumen el monto.
        $subset = $this->subsetSum($facturas, $monto);
        if ($subset !== null) {
            // Múltiples facturas → guardamos la primera como referencia; el
            // humano confirma el conjunto en el modal de modificación.
            return ['confianza' => 85, 'factura_id' => $subset[0]['id'], 'tipo_match' => 'COMPUESTO'];
        }

        return ['confianza' => 60, 'factura_id' => null, 'tipo_match' => 'SOLO_CUIT'];
    }

    /**
     * Subset-sum acotado (hasta 3 facturas) por el monto, tolerancia $0.01.
     * @param  array<int,array{id:int,saldo:float}>  $facturas
     * @return array<int,array{id:int,saldo:float}>|null
     */
    private function subsetSum(array $facturas, float $monto): ?array
    {
        $n = count($facturas);
        if ($n < 2 || $n > 40) return null;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (abs($facturas[$i]['saldo'] + $facturas[$j]['saldo'] - $monto) < 0.01) {
                    return [$facturas[$i], $facturas[$j]];
                }
                for ($k = $j + 1; $k < $n; $k++) {
                    if (abs($facturas[$i]['saldo'] + $facturas[$j]['saldo'] + $facturas[$k]['saldo'] - $monto) < 0.01) {
                        return [$facturas[$i], $facturas[$j], $facturas[$k]];
                    }
                }
            }
        }
        return null;
    }

    private function aplicarResultado(MovimientoBancario $mov, ConciliacionRegla $regla, array $res): void
    {
        DB::transaction(function () use ($mov, $regla, $res) {
            $estadoPrevio = $mov->estado;
            $mov->update([
                'estado' => MovimientoBancario::ESTADO_PENDIENTE === $estadoPrevio ? 'MATCH_AUTO' : $estadoPrevio,
                'regla_aplicada_id' => $regla->id,
                'cuit_extractado' => $res['cuit'],
                'auxiliar_resuelto_id' => $res['auxiliar']->id ?? null,
                'factura_imputada_id' => $res['factura_id'],
                'factura_imputada_tipo' => $res['factura_tipo'],
                'imputacion_confianza' => $res['confianza'],
                'cuenta_contable_propuesta_id' => $regla->cuenta_contable_id ?? $mov->cuenta_contable_propuesta_id,
            ]);

            DB::table('erp_extractos_imputaciones_audit')->insert([
                'movimiento_id' => $mov->id,
                'accion' => 'AUTO_IMPUTAR',
                'user_id' => null,
                'estado_previo' => $estadoPrevio,
                'estado_posterior' => 'MATCH_AUTO',
                'factura_imputada_nueva_id' => $res['factura_id'],
                'motivo' => sprintf('Auto-imputación regla %s · CUIT %s · %s · confianza %d',
                    $regla->codigo, $res['cuit'], $res['tipo_match'], $res['confianza']),
                'snapshot_completo' => json_encode([
                    'regla' => $regla->codigo,
                    'cuit' => $res['cuit'],
                    'auxiliar_id' => $res['auxiliar']->id ?? null,
                    'auxiliar_nombre' => $res['auxiliar']->nombre ?? null,
                    'factura_id' => $res['factura_id'],
                    'factura_tipo' => $res['factura_tipo'],
                    'tipo_match' => $res['tipo_match'],
                    'confianza' => $res['confianza'],
                    'monto' => (float) max($mov->debito, $mov->credito),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }
}
