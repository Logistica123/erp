<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Tesoreria\ChequeRecibido;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * v1.49 Bloque C + v1.50 Bloques B/C — Vinculación de créditos bancarios a
 * recibos. El recibo YA generó su asiento (fecha_cobro) — acá solo se vincula,
 * NUNCA se genera asiento nuevo.
 *
 * v1.50: los recibos con medio CHEQUES_CARTERA también son candidatos (con sus
 * cheques anidados). Al vincular, los cheques EN_CARTERA/VENCIDO del recibo se
 * AUTO-CONFIRMAN como COBRADO con fecha del mov (D-50-4), con trazabilidad en
 * erp_movimientos_bancarios_cheques (confirmado_por_recibo=1). Cheques ya
 * resueltos por otro camino (DESCONTADO/ENDOSADO/RECHAZADO/COBRADO) no se tocan.
 *
 * Nota de modelo: el "cliente" del cheque SIEMPRE viene del recibo
 * (cliente_auxiliar_id NOT NULL) — el modal de asignación previa del spec §6.1
 * no aplica en este schema (no existe cheques.cliente_id suelto).
 */
class ConciliacionRecibosService
{
    private const CODIGOS_NO_DIRECTOS = ['CHEQUES_CARTERA', 'COMP_CC', 'EFECTIVO'];
    private const CHEQUE_PENDIENTE = [ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Recibos candidatos para un crédito:
     *  - medio directo (transferencia/MP): fecha_cobro ± 3 días del mov.
     *  - medio CHEQUES_CARTERA (v1.50): algún cheque del recibo con vto dentro
     *    de ± 7 días del mov (los cheques acreditan por su vencimiento, no por
     *    la fecha del recibo).
     * Siempre: EMITIDO, monto_cobrado ≈ crédito (±$1), no vinculado a otro mov.
     * Cada recibo trae sus cheques anidados (si tiene).
     *
     * @return list<array<string,mixed>>
     */
    public function candidatos(MovimientoBancario $mov): array
    {
        if ((float) $mov->credito <= 0.005) return [];
        $monto = round((float) $mov->credito, 2);
        $f = $mov->fecha;

        $rows = DB::table('erp_recibos as r')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'r.medio_cobro_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('r.estado', 'EMITIDO')
            ->whereRaw('ABS(r.monto_cobrado - ?) <= 1.0', [$monto])
            ->whereNotIn('r.id', fn ($q) => $q->select('recibo_id')->from('erp_movimientos_bancarios_recibos'))
            ->where(function ($w) use ($f) {
                // Medio directo: por fecha de cobro del recibo.
                $w->where(function ($q) use ($f) {
                    $q->whereNotIn('cb.codigo', self::CODIGOS_NO_DIRECTOS)
                      ->whereBetween('r.fecha_cobro', [
                          $f->copy()->subDays(3)->toDateString(),
                          $f->copy()->addDays(3)->toDateString(),
                      ]);
                })
                // v1.50: medio cheques — por vencimiento de algún cheque del recibo.
                ->orWhere(function ($q) use ($f) {
                    $q->where('cb.codigo', 'CHEQUES_CARTERA')
                      ->whereExists(function ($s) use ($f) {
                          $s->select(DB::raw(1))->from('erp_cheques_recibidos as ch')
                            ->whereColumn('ch.recibo_id', 'r.id')
                            ->whereBetween('ch.fecha_pago', [
                                $f->copy()->subDays(7)->toDateString(),
                                $f->copy()->addDays(7)->toDateString(),
                            ]);
                      });
                });
            })
            ->orderBy('r.fecha_cobro')
            ->limit(20)
            ->get([
                'r.id', 'r.punto_venta', 'r.numero', 'r.fecha_emision', 'r.fecha_cobro',
                'r.monto_cobrado', 'r.asiento_id', 'r.cliente_auxiliar_id', 'r.detalle_cobro',
                'cb.nombre as medio_nombre', 'cb.codigo as medio_codigo', 'cb.id as medio_id',
                'a.nombre as cliente_nombre', 'a.cuit as cliente_cuit',
            ]);

        // Cheques anidados por recibo (v1.50 §5.4) + los ya vinculados a otro mov.
        $reciboIds = $rows->pluck('id')->all();
        $chequesPorRecibo = [];
        if ($reciboIds) {
            $vinculados = DB::table('erp_movimientos_bancarios_cheques')->pluck('cheque_recibido_id')->all();
            $chs = DB::table('erp_cheques_recibidos')->whereIn('recibo_id', $reciboIds)->get();
            foreach ($chs as $ch) {
                $chequesPorRecibo[$ch->recibo_id][] = [
                    'cheque_id' => (int) $ch->id,
                    'numero_cheque' => $ch->numero_cheque,
                    'banco_emisor' => $ch->banco_emisor,
                    'importe' => (float) $ch->importe,
                    'fecha_pago' => substr((string) $ch->fecha_pago, 0, 10),
                    'estado_actual' => $ch->estado,
                    'ya_vinculado_otro_mov' => in_array($ch->id, $vinculados, true),
                    'se_auto_confirma' => in_array($ch->estado, self::CHEQUE_PENDIENTE, true)
                        && ! in_array($ch->id, $vinculados, true),
                ];
            }
        }

        return $rows->map(function ($r) use ($mov, $chequesPorRecibo) {
            $score = 80;
            if ((int) $r->medio_id === (int) $mov->cuenta_bancaria_id) $score = 100;
            if ($mov->cuit_contraparte && $r->cliente_cuit && $mov->cuit_contraparte === $r->cliente_cuit) $score = 100;
            return [
                'recibo_id' => (int) $r->id,
                'numero_recibo' => sprintf('%s-%s', $r->punto_venta, $r->numero),
                'cliente_id' => $r->cliente_auxiliar_id ? (int) $r->cliente_auxiliar_id : null,
                'cliente_nombre' => $r->cliente_nombre,
                'fecha_emision' => (string) $r->fecha_emision,
                'fecha_cobro' => (string) $r->fecha_cobro,
                'monto_cobrado' => (float) $r->monto_cobrado,
                'medio_cobro' => $r->medio_nombre,
                'medio_es_cheques' => $r->medio_codigo === 'CHEQUES_CARTERA',
                'detalle_cobro' => $r->detalle_cobro,
                'asiento_id' => $r->asiento_id ? (int) $r->asiento_id : null,
                'cheques' => $chequesPorRecibo[$r->id] ?? [],
                'match_score' => $score,
            ];
        })->all();
    }

    /**
     * Vincula el mov a uno o más recibos. Sin asiento nuevo. v1.50: los cheques
     * EN_CARTERA/VENCIDO de los recibos se auto-confirman como COBRADO con
     * fecha del mov, con trazabilidad (confirmado_por_recibo=1). Todo en una
     * transacción única (rollback completo si algo falla).
     *
     * @param  list<array{recibo_id:int, monto:float|string}>  $recibos
     */
    public function vincular(MovimientoBancario $mov, array $recibos, User $usuario): MovimientoBancario
    {
        if (! in_array($mov->estado, [MovimientoBancario::ESTADO_PENDIENTE, MovimientoBancario::ESTADO_ETIQUETADO, MovimientoBancario::ESTADO_MATCH_AUTO], true)) {
            throw new DomainException('MOV_NO_PENDIENTE: estado actual '.$mov->estado);
        }
        if ((float) $mov->credito <= 0.005) {
            throw new DomainException('MOV_NO_ES_CREDITO');
        }
        if (empty($recibos)) throw new DomainException('SIN_RECIBOS');

        $monto = round((float) $mov->credito, 2);
        $suma = round(array_sum(array_map(fn ($r) => (float) $r['monto'], $recibos)), 2);
        if (abs($suma - $monto) > 1.0) {
            throw new DomainException(sprintf(
                'RECIBOS_DESCUADRAN: la suma de recibos ($%s) debe igualar el crédito del mov ($%s).',
                number_format($suma, 2, ',', '.'), number_format($monto, 2, ',', '.'),
            ));
        }

        return DB::transaction(function () use ($mov, $recibos, $usuario, $monto) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $nros = [];
            $chequesConfirmados = [];
            foreach ($recibos as $r) {
                $recibo = DB::table('erp_recibos')->where('id', (int) $r['recibo_id'])->first();
                if (! $recibo) throw new DomainException('RECIBO_NO_ENCONTRADO: #'.$r['recibo_id']);
                if ($recibo->estado !== 'EMITIDO') {
                    throw new DomainException("RECIBO_NO_EMITIDO: #{$recibo->id} está {$recibo->estado}.");
                }
                $vinculado = DB::table('erp_movimientos_bancarios_recibos')
                    ->where('recibo_id', $recibo->id)->exists();
                if ($vinculado) {
                    throw new DomainException("RECIBO_YA_VINCULADO: #{$recibo->id} ya está vinculado a otro movimiento.");
                }
                $vinculacionId = DB::table('erp_movimientos_bancarios_recibos')->insertGetId([
                    'mov_bancario_id' => $mov->id,
                    'recibo_id' => $recibo->id,
                    'monto_imputado' => round((float) $r['monto'], 2),
                    'created_by' => $usuario->id,
                    'created_at' => now(),
                ]);
                $nros[] = sprintf('%s-%s', $recibo->punto_venta, $recibo->numero);

                // v1.50 D-50-4 — auto-confirmar los cheques pendientes del recibo.
                $cheques = ChequeRecibido::where('recibo_id', $recibo->id)
                    ->whereIn('estado', self::CHEQUE_PENDIENTE)->get();
                foreach ($cheques as $ch) {
                    $yaVinc = DB::table('erp_movimientos_bancarios_cheques')
                        ->where('cheque_recibido_id', $ch->id)->exists();
                    if ($yaVinc) {
                        throw new DomainException("CHEQUE_YA_VINCULADO: el cheque #{$ch->numero_cheque} del recibo ya está vinculado a otro movimiento.");
                    }
                    $estadoPrevio = $ch->estado;
                    $ch->update([
                        'estado' => ChequeRecibido::ESTADO_COBRADO,
                        'fecha_acreditacion' => $mov->fecha->toDateString(),
                        'fecha_deposito' => $ch->fecha_deposito ?: $mov->fecha->toDateString(),
                        'cuenta_bancaria_deposito_id' => $ch->cuenta_bancaria_deposito_id ?: $mov->cuenta_bancaria_id,
                    ]);
                    DB::table('erp_movimientos_bancarios_cheques')->insert([
                        'mov_bancario_id' => $mov->id,
                        'cheque_recibido_id' => $ch->id,
                        'monto_imputado' => round((float) $ch->importe, 2),
                        'cheque_estado_previo' => $estadoPrevio,
                        'confirmado_por_recibo' => 1,
                        'vinculacion_mov_recibo_id' => $vinculacionId,
                        'created_by' => $usuario->id,
                        'created_at' => now(),
                    ]);
                    $chequesConfirmados[] = (int) $ch->id;
                }
            }

            $conCheques = count($chequesConfirmados) > 0;
            $motivoId = DB::table('erp_conciliacion_motivos')
                ->where('codigo', $conCheques ? 'RECIBO-CON-CHEQUES' : 'RECIBO-DIRECTO')->value('id');
            $mov->update([
                'estado' => $conCheques
                    ? 'CONFIRMADO_RECIBO_CON_CHEQUES'
                    : MovimientoBancario::ESTADO_CONFIRMADO_RECIBO,
                'monto_conciliado' => $monto,
                'motivo_diferencia_id' => $motivoId,
                'observacion' => trim(($mov->observacion ? $mov->observacion.' · ' : '')
                    .'Vinculado a recibo(s) '.implode(', ', $nros)
                    .($conCheques ? ' — '.count($chequesConfirmados).' cheque(s) auto-confirmado(s) COBRADO' : '')
                    .' (asiento del recibo, sin duplicar)'),
            ]);

            $this->audit->logEvento(
                accion: $conCheques ? 'MOV_VINCULADO_RECIBO_CHEQUES' : 'MOV_VINCULADO_RECIBO',
                modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d vinculado a recibo(s) %s por $%s%s',
                    $mov->id, implode(', ', $nros), number_format($monto, 2, ',', '.'),
                    $conCheques ? ' · cheques auto-confirmados: '.count($chequesConfirmados) : ''),
                empresaId: $mov->cuentaBancaria?->empresa_id,
            );

            return $mov->fresh();
        });
    }

    /**
     * Reversa: los cheques auto-confirmados por esta vinculación vuelven a su
     * estado previo (fecha_acreditacion = NULL); se eliminan las vinculaciones.
     * El asiento del recibo NO se toca. Llamada desde
     * ConciliacionService::desconciliar (dentro de su transacción).
     */
    public function desvincular(MovimientoBancario $mov): void
    {
        // v1.50 — restaurar cheques auto-confirmados vía recibo.
        $links = DB::table('erp_movimientos_bancarios_cheques')
            ->where('mov_bancario_id', $mov->id)
            ->where('confirmado_por_recibo', 1)->get();
        foreach ($links as $l) {
            DB::table('erp_cheques_recibidos')->where('id', $l->cheque_recibido_id)->update([
                'estado' => $l->cheque_estado_previo,
                'fecha_acreditacion' => null,
                'updated_at' => now(),
            ]);
        }
        DB::table('erp_movimientos_bancarios_cheques')
            ->where('mov_bancario_id', $mov->id)
            ->where('confirmado_por_recibo', 1)->delete();

        DB::table('erp_movimientos_bancarios_recibos')->where('mov_bancario_id', $mov->id)->delete();
    }
}
