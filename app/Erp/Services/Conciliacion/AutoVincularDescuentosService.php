<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Tesoreria\ChequeRecibido;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v1.49 Bloque B — Vinculación de créditos bancarios al asiento existente de un
 * DESCUENTO de cheque (Flujo C). El descuento YA generó su asiento (D banco por
 * el neto + costos / H 1.1.4.04) — acá solo se vincula, NUNCA se genera asiento
 * nuevo (evita duplicar).
 *
 * Los candidatos se resuelven desde los cheques DESCONTADO (que guardan
 * descuento_neto + asiento_id + cuenta + fecha), no escaneando asientos: es el
 * modelo real del ciclo 2026-07-03 (el spec §5.1 asumía un campo tipo en
 * erp_asientos que no existe).
 */
class AutoVincularDescuentosService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * D-49-3 — corre al final de cada import de extracto: auto-vincula los movs
     * crédito PENDIENTE/ETIQUETADO que matchean EXACTO con un único descuento
     * (importe == descuento_neto ±$1, fecha ±3 días, misma cuenta bancaria).
     *
     * @return array{vinculados:int, ambiguos:int}
     */
    public function run(int $extractoId, ?int $usuarioId = null): array
    {
        $movs = MovimientoBancario::where('extracto_id', $extractoId)
            ->whereIn('estado', [MovimientoBancario::ESTADO_PENDIENTE, MovimientoBancario::ESTADO_ETIQUETADO])
            ->where('credito', '>', 0)
            ->get();

        $vinculados = 0;
        $ambiguos = 0;
        foreach ($movs as $mov) {
            $candidatos = $this->candidatos($mov, 3);
            if (count($candidatos) === 1) {
                $this->vincular($mov, (int) $candidatos[0]['asiento_id'], null, $usuarioId, auto: true);
                $vinculados++;
                Log::info("v1.49 auto-vinculado mov #{$mov->id} a asiento descuento #{$candidatos[0]['asiento_id']}");
            } elseif (count($candidatos) > 1) {
                $ambiguos++;
            }
        }

        return ['vinculados' => $vinculados, 'ambiguos' => $ambiguos];
    }

    /**
     * Asientos de descuento candidatos para un mov (para el panel del modal).
     * Rango por defecto ±7 días en el manual; el auto usa ±3 (D-49-3).
     *
     * @return list<array<string,mixed>>
     */
    public function candidatos(MovimientoBancario $mov, int $rangoDias = 7): array
    {
        if ((float) $mov->credito <= 0.005) return [];
        $monto = round((float) $mov->credito, 2);

        $rows = DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('c.estado', ChequeRecibido::ESTADO_DESCONTADO)
            ->whereNotNull('c.asiento_id')
            ->where('c.cuenta_bancaria_deposito_id', $mov->cuenta_bancaria_id)
            ->whereRaw('ABS(c.descuento_neto - ?) <= 1.0', [$monto])
            ->whereBetween('c.fecha_acreditacion', [
                $mov->fecha->copy()->subDays($rangoDias)->toDateString(),
                $mov->fecha->copy()->addDays($rangoDias)->toDateString(),
            ])
            ->whereNotIn('c.asiento_id', fn ($q) => $q->select('asiento_descuento_vinculado_id')
                ->from('erp_movimientos_bancarios')
                ->whereNotNull('asiento_descuento_vinculado_id'))
            ->orderBy('c.fecha_acreditacion')
            ->limit(20)
            ->get([
                'c.id as cheque_id', 'c.numero_cheque', 'c.banco_emisor', 'c.importe',
                'c.descuento_neto', 'c.descuento_entidad', 'c.fecha_acreditacion', 'c.asiento_id',
                'a.nombre as cliente_nombre',
            ]);

        return $rows->map(fn ($c) => [
            'asiento_id' => (int) $c->asiento_id,
            'cheque_id' => (int) $c->cheque_id,
            'numero_cheque' => $c->numero_cheque,
            'banco_emisor' => $c->banco_emisor,
            'entidad_descuento' => $c->descuento_entidad,
            'cliente_nombre' => $c->cliente_nombre,
            'importe_cheque' => (float) $c->importe,
            'neto' => (float) $c->descuento_neto,
            'fecha' => (string) $c->fecha_acreditacion,
        ])->all();
    }

    /**
     * Vincula el mov al asiento de descuento (§5.3). No genera asiento nuevo.
     */
    public function vincular(MovimientoBancario $mov, int $asientoId, ?User $usuario = null, ?int $usuarioId = null, bool $auto = false): MovimientoBancario
    {
        $usuarioId = $usuario?->id ?? $usuarioId ?? 1;

        if (! in_array($mov->estado, [MovimientoBancario::ESTADO_PENDIENTE, MovimientoBancario::ESTADO_ETIQUETADO, MovimientoBancario::ESTADO_MATCH_AUTO], true)) {
            throw new DomainException('MOV_NO_PENDIENTE: estado actual '.$mov->estado);
        }
        // El asiento debe ser de un descuento de cheque real.
        $cheque = ChequeRecibido::where('asiento_id', $asientoId)
            ->where('estado', ChequeRecibido::ESTADO_DESCONTADO)->first();
        if (! $cheque) {
            throw new DomainException('ASIENTO_NO_ES_DESCUENTO: el asiento #'.$asientoId.' no corresponde a un descuento de cheque vigente.');
        }
        // Invariante 5: un asiento de descuento solo puede vincularse a UN mov.
        $otro = MovimientoBancario::where('asiento_descuento_vinculado_id', $asientoId)
            ->where('id', '!=', $mov->id)->first();
        if ($otro) {
            throw new DomainException("DESCUENTO_YA_VINCULADO: el asiento #{$asientoId} ya está vinculado al mov #{$otro->id}.");
        }

        $motivoId = DB::table('erp_conciliacion_motivos')->where('codigo', 'CHEQUE-DESCONTADO')->value('id');
        $mov->update([
            'estado' => MovimientoBancario::ESTADO_CONFIRMADO_DESCUENTO,
            'asiento_descuento_vinculado_id' => $asientoId,
            'monto_conciliado' => round((float) $mov->credito, 2),
            'motivo_diferencia_id' => $motivoId,
            'observacion' => trim(($mov->observacion ? $mov->observacion.' · ' : '')
                .sprintf('Vinculado a asiento descuento #%d (cheque #%s%s)%s',
                    $asientoId, $cheque->numero_cheque,
                    $cheque->descuento_entidad ? ' en '.$cheque->descuento_entidad : '',
                    $auto ? ' [auto]' : '')),
        ]);

        $this->audit->logEvento(
            accion: 'MOV_VINCULADO_DESCUENTO',
            modulo: 'tesoreria',
            descripcion: sprintf('Mov #%d vinculado a asiento de descuento #%d (cheque #%s)%s',
                $mov->id, $asientoId, $cheque->numero_cheque, $auto ? ' [auto post-import]' : ''),
            empresaId: $mov->cuentaBancaria?->empresa_id,
        );

        return $mov->fresh();
    }

    /**
     * Reversa (§5.4): solo desvincula — el asiento del descuento NO se toca.
     * Llamada desde ConciliacionService::desconciliar.
     */
    public function desvincular(MovimientoBancario $mov): void
    {
        $mov->asiento_descuento_vinculado_id = null;
        $mov->save();
    }
}
