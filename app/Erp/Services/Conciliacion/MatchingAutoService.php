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

        // El patrón actúa de gatillo (debe matchear el concepto).
        if (! @preg_match("/{$extractor}/iu", (string) $mov->concepto, $m)) {
            return null;
        }

        // CUIT: prioridad 1 grupo de captura del concepto; prioridad 2 la
        // columna `Nro doc` del CSV (v1.47 §4 — el parser ICBC ya la normalizó
        // en cuit_contraparte). Esto cubre las reglas v1.47 cuyo regex ya no
        // trae el CUIT pegado al concepto.
        $cuit = null;
        if (isset($m[1])) {
            $cuit = preg_replace('/[^0-9]/', '', $m[1]);
        }
        if (! $cuit || strlen($cuit) !== 11) {
            $cuit = preg_replace('/[^0-9]/', '', (string) $mov->cuit_contraparte);
        }
        $cuitValido = $cuit && strlen($cuit) === 11;

        $tipos = match ($regla->tipo_auxiliar) {
            'CLIENTE' => ['Cliente'],
            'PROVEEDOR' => ['Proveedor', 'Distribuidor'],
            'DISTRIBUIDOR' => ['Distribuidor', 'Proveedor'],
            'EMPLEADO' => ['Empleado'],
            default => ['Cliente', 'Proveedor', 'Distribuidor', 'Empleado'],
        };

        // Branch 1 — por CUIT.
        $auxiliar = null;
        if ($cuitValido) {
            $auxiliar = DB::table('erp_auxiliares')
                ->where('cuit', $cuit)
                ->whereIn('tipo', $tipos)
                ->orderByRaw("FIELD(tipo, '" . implode("','", $tipos) . "')")
                ->first();
        }

        // Branch 2 (v1.48 Bloque A) — fuzzy por nombre, si la regla lo permite
        // y el CUIT no resolvió auxiliar.
        $matchCap = null; // tope de confianza cuando vino por nombre
        if (! $auxiliar && $regla->matching_auto_por_nombre) {
            $nombre = $this->extraerNombre($mov, $regla);
            if ($nombre) {
                $cands = $this->buscarAuxiliaresPorNombre($nombre, $tipos);
                if (count($cands) === 1) {
                    $auxiliar = $cands[0];
                    $matchCap = 75; // D-48-2 tope fuzzy
                } elseif (count($cands) > 1) {
                    return ['cuit' => $cuitValido ? $cuit : null, 'auxiliar' => null, 'confianza' => 60,
                            'factura_id' => null, 'factura_tipo' => null, 'tipo_match' => 'NOMBRE_AMBIGUO'];
                }
            }
        }

        if (! $auxiliar) {
            if ($cuitValido) {
                return ['cuit' => $cuit, 'auxiliar' => null, 'confianza' => 0,
                        'factura_id' => null, 'factura_tipo' => null, 'tipo_match' => 'SIN_AUXILIAR'];
            }
            return null;
        }
        $cuit = $cuitValido ? $cuit : $auxiliar->cuit;

        // v1.47 §5 — routing por tipo de auxiliar. EMPLEADO → pago de sueldo:
        // no se busca factura, va directo a 5.2.1.01 Sueldos Administración.
        if ($auxiliar->tipo === 'Empleado') {
            $ctaSueldos = DB::table('erp_cuentas_contables')
                ->where('empresa_id', (int) (DB::table('erp_cuentas_bancarias')->where('id', $mov->cuenta_bancaria_id)->value('empresa_id') ?: 1))
                ->where('codigo', '5.2.1.01')->value('id');
            return [
                'cuit' => $cuit, 'auxiliar' => $auxiliar, 'confianza' => 90,
                'factura_id' => null, 'factura_tipo' => null, 'tipo_match' => 'EMPLEADO_SUELDO',
                'cuenta_override_id' => $ctaSueldos ? (int) $ctaSueldos : null,
            ];
        }

        $esVenta = (float) $mov->credito > 0.005;
        $facturaTipo = $esVenta ? 'VENTA' : 'COMPRA';
        $monto = (float) max($mov->debito, $mov->credito);

        $facturas = $this->facturasPendientes($auxiliar->id, $esVenta, (int) $mov->cuenta_bancaria_id);

        $match = $this->matchearMonto($monto, $facturas);

        // v1.48 Bloque A — si el auxiliar vino por nombre (fuzzy) la confianza
        // se topea (D-48-2) y se marca el tipo de match.
        $confianza = $match['confianza'];
        $tipoMatch = $match['tipo_match'];
        if ($matchCap !== null) {
            $confianza = min($confianza, $matchCap);
            $tipoMatch = 'POR_NOMBRE_' . $tipoMatch;
        }

        return [
            'cuit' => $cuit,
            'auxiliar' => $auxiliar,
            'confianza' => $confianza,
            'factura_id' => $match['factura_id'],
            'factura_tipo' => $match['factura_id'] ? $facturaTipo : null,
            'tipo_match' => $tipoMatch,
        ];
    }

    /**
     * Extrae el nombre de la contraparte para matching fuzzy (Bloque A v1.48).
     * Prioridad: regex extractor de nombre de la regla sobre el concepto;
     * luego la columna nombre_contraparte que dejó el parser.
     */
    private function extraerNombre(MovimientoBancario $mov, ConciliacionRegla $regla): ?string
    {
        if ($regla->nombre_extractor_regex
            && @preg_match("/{$regla->nombre_extractor_regex}/iu", (string) $mov->concepto, $mm)
            && isset($mm[1]) && trim($mm[1]) !== '') {
            return trim($mm[1]);
        }
        $n = trim((string) ($mov->nombre_contraparte ?? ''));
        return $n !== '' ? $n : null;
    }

    /**
     * Busca auxiliares por nombre normalizado: exacto → LIKE → levenshtein ≤ 3.
     * Devuelve el primer escalón no vacío (para no mezclar grados de certeza).
     * @return array<int,object>
     */
    private function buscarAuxiliaresPorNombre(string $nombre, array $tipos): array
    {
        $norm = $this->normalizar($nombre);
        if ($norm === '') return [];

        $base = fn () => DB::table('erp_auxiliares')->whereIn('tipo', $tipos)->whereNull('deleted_at');

        // 1) exacto sobre la columna generada.
        $exact = $base()->where('razon_social_normalizada', $norm)->get()->all();
        if (count($exact) >= 1) return $exact;

        // 2) LIKE (contiene / contenido).
        $like = $base()
            ->where(function ($q) use ($norm) {
                $q->where('razon_social_normalizada', 'like', "%{$norm}%")
                  ->orWhereRaw('? like concat("%", razon_social_normalizada, "%")', [$norm]);
            })
            ->get()->all();
        if (count($like) >= 1) return $like;

        // 3) levenshtein ≤ 3 en PHP (universo acotado a los tipos).
        $cands = [];
        foreach ($base()->get(['id', 'nombre', 'cuit', 'tipo', 'razon_social_normalizada']) as $a) {
            $rn = (string) ($a->razon_social_normalizada ?? '');
            if ($rn === '') continue;
            if (levenshtein(substr($norm, 0, 255), substr($rn, 0, 255)) <= 3) {
                $cands[] = $a;
            }
        }
        return $cands;
    }

    /** Normaliza igual que la columna generada: upper, sin tildes/no-alfanum, espacios colapsados. */
    private function normalizar(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $s = strtr($s, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U']);
        $s = preg_replace('/[^A-Z0-9 ]/', '', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';
        return trim($s);
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
                'cuenta_contable_propuesta_id' => $res['cuenta_override_id'] ?? $regla->cuenta_contable_id ?? $mov->cuenta_contable_propuesta_id,
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
