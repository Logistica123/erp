<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Support\AuditLogger;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calendario de cobros proyectados (pedido 2026-07-15):
 *
 *  - FACTURAS de venta NO pagadas (saldo > 0): fecha proyectada =
 *    FECHA DE FACTURA (fecha_emision, NO el vto) + plazo_cobro_dias del
 *    cliente. Clientes sin plazo cargado van aparte (sin_plazo) para que
 *    el tesorero los complete.
 *  - CHEQUES recibidos EN_CARTERA (y DEPOSITADOS sin acreditar): fecha =
 *    fecha_pago (el vencimiento estimado cargado en el recibo).
 *
 * El saldo por factura replica en bulk la lógica de
 * ReciboService::saldoFactura (cobro_items + NC imputadas + recibos
 * multi-comprobante + fallback legacy 1:1).
 */
class CalendarioCobrosService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Panel de plazos: clientes activos con su plazo y su expuesto. */
    public function plazos(int $empresaId = 1): array
    {
        $rows = DB::table('erp_auxiliares as a')
            ->where('a.empresa_id', $empresaId)
            ->where('a.tipo', 'Cliente')
            ->where('a.activo', 1)
            ->orderBy('a.nombre')
            ->get(['a.id', 'a.codigo', 'a.nombre', 'a.cuit', 'a.plazo_cobro_dias']);

        return $rows->map(fn ($r) => [
            'auxiliar_id' => (int) $r->id,
            'codigo' => $r->codigo,
            'nombre' => $r->nombre,
            'cuit' => $r->cuit,
            'plazo_cobro_dias' => $r->plazo_cobro_dias !== null ? (int) $r->plazo_cobro_dias : null,
        ])->all();
    }

    public function guardarPlazo(int $auxiliarId, ?int $dias, int $userId, int $empresaId = 1): void
    {
        $aux = DB::table('erp_auxiliares')->where('id', $auxiliarId)
            ->where('empresa_id', $empresaId)->first(['id', 'tipo', 'codigo', 'plazo_cobro_dias']);
        if (! $aux) {
            throw new DomainException('AUXILIAR_INEXISTENTE');
        }
        if ($aux->tipo !== 'Cliente') {
            throw new DomainException('NO_ES_CLIENTE: el plazo de cobro aplica a auxiliares tipo Cliente.');
        }
        if ($dias !== null && ($dias < 0 || $dias > 365)) {
            throw new DomainException('PLAZO_INVALIDO: entre 0 y 365 días (o vacío).');
        }

        DB::table('erp_auxiliares')->where('id', $aux->id)->update(['plazo_cobro_dias' => $dias]);

        $this->audit->logEvento(
            accion: 'PLAZO_COBRO_ACTUALIZADO',
            modulo: 'tesoreria',
            descripcion: sprintf('Plazo de cobro de %s: %s → %s días (user #%d).',
                $aux->codigo, $aux->plazo_cobro_dias ?? '—', $dias ?? '—', $userId),
            empresaId: $empresaId,
        );
    }

    /**
     * @return array{items: array, por_dia: array, sin_plazo: array, totales: array}
     */
    public function calendario(Carbon $desde, Carbon $hasta, int $empresaId = 1): array
    {
        $items = array_merge(
            $this->facturasProyectadas($empresaId),
            $this->chequesEnCartera($empresaId),
        );

        // Filtrar por rango pedido; lo vencido (fecha < hoy) se reasigna a
        // HOY en el bucket (plata que "debería estar entrando ya").
        $hoy = now()->startOfDay();
        $enRango = [];
        $sinPlazo = [];
        $aRevisar = [];
        foreach ($items as $it) {
            if ($it['fecha'] === null) {
                $sinPlazo[] = $it;

                continue;
            }
            $f = Carbon::parse($it['fecha']);
            $it['vencido'] = $f->lt($hoy);

            // Un CHEQUE con vencimiento pasado no es un cobro futuro: ya se
            // puede (o se pudo) depositar — casi seguro falta registrarlo.
            // Va a 'a_revisar', NO al calendario (fix 2026-07-16, reporte
            // de Francisco: cheques de mayo/junio apareciendo hoy).
            if ($it['tipo'] === 'CHEQUE' && $it['vencido']) {
                $it['dias_vencido'] = (int) $f->diffInDays($hoy);
                $aRevisar[] = $it;

                continue;
            }

            $it['fecha_bucket'] = ($f->lt($hoy) ? $hoy : $f)->toDateString();
            if ($f->gt($hasta) || Carbon::parse($it['fecha_bucket'])->lt($desde)) {
                continue;
            }
            $enRango[] = $it;
        }

        $porDia = [];
        foreach ($enRango as $it) {
            $d = $it['fecha_bucket'];
            $porDia[$d] = $porDia[$d] ?? ['fecha' => $d, 'total' => 0.0, 'facturas' => 0.0, 'cheques' => 0.0, 'items' => 0];
            $porDia[$d]['total'] += $it['importe'];
            $porDia[$d][$it['tipo'] === 'FACTURA' ? 'facturas' : 'cheques'] += $it['importe'];
            $porDia[$d]['items']++;
        }
        ksort($porDia);
        foreach ($porDia as &$d) {
            foreach (['total', 'facturas', 'cheques'] as $k) {
                $d[$k] = round($d[$k], 2);
            }
        }

        usort($enRango, fn ($a, $b) => [$a['fecha_bucket'], $a['tipo']] <=> [$b['fecha_bucket'], $b['tipo']]);

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'items' => $enRango,
            'por_dia' => array_values($porDia),
            'sin_plazo' => $sinPlazo,
            'a_revisar' => $aRevisar,
            'totales' => [
                'total' => round(array_sum(array_column($enRango, 'importe')), 2),
                'facturas' => round(array_sum(array_map(fn ($i) => $i['tipo'] === 'FACTURA' ? $i['importe'] : 0, $enRango)), 2),
                'cheques' => round(array_sum(array_map(fn ($i) => $i['tipo'] === 'CHEQUE' ? $i['importe'] : 0, $enRango)), 2),
                'vencido' => round(array_sum(array_map(fn ($i) => ! empty($i['vencido']) ? $i['importe'] : 0, $enRango)), 2),
                'sin_plazo' => round(array_sum(array_column($sinPlazo, 'importe')), 2),
                'a_revisar' => round(array_sum(array_column($aRevisar, 'importe')), 2),
            ],
        ];
    }

    /** Facturas de venta con saldo pendiente, proyectadas por plazo del cliente. */
    private function facturasProyectadas(int $empresaId): array
    {
        $rows = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', $empresaId)
            ->whereNull('f.deleted_at')
            ->where('tc.signo', 1)                    // facturas/ND (las NC no se cobran)
            ->whereNotIn('f.estado', ['ANULADA', 'BORRADOR'])
            ->selectRaw("f.id, f.fecha_emision, f.imp_total, f.numero, f.punto_venta_id,
                tc.codigo_interno tipo, a.id auxiliar_id, a.nombre cliente, a.plazo_cobro_dias,
                COALESCE((SELECT SUM(ci.importe) FROM erp_cobro_items ci
                    WHERE ci.factura_id = f.id AND ci.tipo_item = 'FACTURA_VENTA'), 0) cobrado,
                COALESCE((SELECT SUM(inc.importe) FROM erp_imputaciones_nc inc
                    WHERE inc.factura_id = f.id AND inc.empresa_id = f.empresa_id), 0) nc,
                COALESCE((SELECT SUM(rci.monto_imputado) FROM erp_recibos_comprobantes_imputados rci
                    JOIN erp_recibos r ON r.id = rci.recibo_id AND r.estado <> 'ANULADO'
                    WHERE rci.factura_venta_id = f.id), 0) imputado,
                COALESCE((SELECT SUM(r2.monto_cobrado) FROM erp_recibos r2
                    WHERE r2.factura_venta_id = f.id AND r2.estado <> 'ANULADO'
                      AND NOT EXISTS (SELECT 1 FROM erp_recibos_comprobantes_imputados rci2
                                      WHERE rci2.recibo_id = r2.id)), 0) legacy")
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $saldo = round((float) $r->imp_total - (float) $r->cobrado - (float) $r->nc
                - (float) $r->imputado - (float) $r->legacy, 2);
            if ($saldo <= 0.009) {
                continue; // pagada
            }
            $fecha = $r->plazo_cobro_dias !== null
                ? Carbon::parse($r->fecha_emision)->addDays((int) $r->plazo_cobro_dias)->toDateString()
                : null;
            $items[] = [
                'tipo' => 'FACTURA',
                'id' => (int) $r->id,
                'referencia' => trim(($r->tipo ?? 'FC').' '.$r->numero),
                'cliente' => $r->cliente,
                'auxiliar_id' => (int) $r->auxiliar_id,
                'fecha_origen' => substr((string) $r->fecha_emision, 0, 10),
                'plazo_dias' => $r->plazo_cobro_dias !== null ? (int) $r->plazo_cobro_dias : null,
                'fecha' => $fecha,
                'importe' => $saldo,
            ];
        }

        return $items;
    }

    /** Cheques en cartera (o depositados sin acreditar) por su vencimiento del recibo. */
    private function chequesEnCartera(int $empresaId): array
    {
        $rows = DB::table('erp_cheques_recibidos as ch')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'ch.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('ch.empresa_id', $empresaId)
            ->whereIn('ch.estado', ['EN_CARTERA', 'DEPOSITADO'])
            ->get(['ch.id', 'ch.numero_cheque', 'ch.banco_emisor', 'ch.librador_nombre',
                'ch.importe', 'ch.fecha_pago', 'ch.estado', 'a.nombre as cliente', 'a.id as auxiliar_id']);

        return $rows->map(fn ($r) => [
            'tipo' => 'CHEQUE',
            'id' => (int) $r->id,
            'referencia' => 'Cheque '.$r->numero_cheque.($r->banco_emisor ? ' · '.$r->banco_emisor : ''),
            'cliente' => $r->cliente ?? $r->librador_nombre,
            'auxiliar_id' => $r->auxiliar_id !== null ? (int) $r->auxiliar_id : null,
            'fecha_origen' => $r->fecha_pago ? substr((string) $r->fecha_pago, 0, 10) : null,
            'plazo_dias' => null,
            'estado_cheque' => $r->estado,
            'fecha' => $r->fecha_pago ? substr((string) $r->fecha_pago, 0, 10) : null,
            'importe' => round((float) $r->importe, 2),
        ])->all();
    }
}
