<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Tesoreria\ChequeRecibido;
use App\Erp\Models\Tesoreria\Conciliacion;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * v1.49 Bloque A — Conciliación de créditos bancarios contra cheques recibidos
 * (Flujo B: cheque depositado → acreditado, posiblemente en lote N:1).
 *
 * El "Depositar" del módulo de cheques NO genera asiento — el asiento nace acá,
 * al conciliar el crédito del extracto:
 *   D banco (cuenta del mov) / H 1.1.4.04 Valores al Cobro (una línea por
 *   cliente, con auxiliar).
 */
class ConciliacionChequesService
{
    private const CUENTA_VALORES_AL_COBRO = '1.1.4.04';

    /** Estados de cheque aceptados como candidatos (D-49-1 + vencidos). */
    private const ESTADOS_CANDIDATO = [
        ChequeRecibido::ESTADO_EN_CARTERA,
        ChequeRecibido::ESTADO_VENCIDO,
        ChequeRecibido::ESTADO_COBRADO,
    ];

    public function __construct(
        private readonly AsientoService $asientos,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cheques candidatos para un mov de crédito (D-49-2: vencimiento dentro de
     * mov.fecha ± 7 días; excluye ya vinculados a otro mov).
     *
     * @return list<array<string,mixed>>
     */
    public function candidatos(MovimientoBancario $mov, ?string $desde = null, ?string $hasta = null): array
    {
        if ((float) $mov->credito <= 0.005) return [];

        $desde = $desde ?: $mov->fecha->copy()->subDays(7)->toDateString();
        $hasta = $hasta ?: $mov->fecha->copy()->addDays(7)->toDateString();

        $rows = DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->whereIn('c.estado', self::ESTADOS_CANDIDATO)
            ->whereNotIn('c.id', fn ($q) => $q->select('cheque_recibido_id')->from('erp_movimientos_bancarios_cheques'))
            ->whereBetween('c.fecha_pago', [$desde, $hasta])
            ->orderBy('c.fecha_pago')
            ->limit(100)
            ->get([
                'c.id', 'c.numero_cheque', 'c.banco_emisor', 'c.importe', 'c.estado',
                'c.fecha_emision', 'c.fecha_pago', 'c.fecha_acreditacion', 'c.recibo_id',
                'r.cliente_auxiliar_id', 'r.punto_venta as recibo_pv', 'r.numero as recibo_numero',
                'a.nombre as cliente_nombre',
            ]);

        $montoMov = round((float) $mov->credito, 2);

        return $rows->map(fn ($c) => [
            'cheque_id' => (int) $c->id,
            'numero_cheque' => $c->numero_cheque,
            'banco_emisor' => $c->banco_emisor,
            'cliente_id' => $c->cliente_auxiliar_id ? (int) $c->cliente_auxiliar_id : null,
            'cliente_nombre' => $c->cliente_nombre,
            'importe' => (float) $c->importe,
            'fecha_emision' => (string) $c->fecha_emision,
            'fecha_vencimiento' => (string) $c->fecha_pago,
            'estado_actual' => $c->estado,
            'recibo_origen_id' => $c->recibo_id ? (int) $c->recibo_id : null,
            'recibo_numero' => $c->recibo_pv ? sprintf('%s-%s', $c->recibo_pv, $c->recibo_numero) : null,
            // 100 si el importe individual cubre el mov exacto; 80 si entra como parte de un lote.
            'match_score' => abs((float) $c->importe - $montoMov) <= 1.0 ? 100 : 80,
        ])->all();
    }

    /**
     * Concilia el mov contra N cheques (§4.2). Genera 1 asiento consolidado,
     * pasa los cheques pendientes a COBRADO y deja el mov en
     * CONFIRMADO_CHEQUES_COBRADOS.
     *
     * @param  list<array{cheque_id:int, monto:float|string}>  $cheques
     */
    public function conciliar(MovimientoBancario $mov, array $cheques, ?string $observaciones, User $usuario): MovimientoBancario
    {
        if (! in_array($mov->estado, [MovimientoBancario::ESTADO_PENDIENTE, MovimientoBancario::ESTADO_ETIQUETADO, MovimientoBancario::ESTADO_MATCH_AUTO], true)) {
            throw new DomainException('MOV_NO_PENDIENTE: estado actual '.$mov->estado);
        }
        if ((float) $mov->credito <= 0.005) {
            throw new DomainException('MOV_NO_ES_CREDITO: solo se concilian créditos contra cheques.');
        }
        if (empty($cheques)) throw new DomainException('SIN_CHEQUES');

        $montoMov = round((float) $mov->credito, 2);
        $suma = round(array_sum(array_map(fn ($c) => (float) $c['monto'], $cheques)), 2);
        if (abs($suma - $montoMov) > 1.0) {
            throw new DomainException(sprintf(
                'CHEQUES_DESCUADRAN: la suma de cheques ($%s) debe igualar el crédito del mov ($%s).',
                number_format($suma, 2, ',', '.'), number_format($montoMov, 2, ',', '.'),
            ));
        }

        $cuentaBanco = $mov->cuentaBancaria;
        $empresaId = (int) $cuentaBanco->empresa_id;
        $ctaValoresId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', self::CUENTA_VALORES_AL_COBRO)->value('id');
        if (! $ctaValoresId) throw new DomainException('CUENTA_NO_EXISTE: '.self::CUENTA_VALORES_AL_COBRO);
        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioId) throw new DomainException('DIARIO_BAN_INEXISTENTE');

        // Resolver y validar cada cheque.
        $items = [];
        foreach ($cheques as $c) {
            $cheque = ChequeRecibido::find((int) $c['cheque_id']);
            if (! $cheque) throw new DomainException('CHEQUE_NO_ENCONTRADO: #'.$c['cheque_id']);
            if (! in_array($cheque->estado, self::ESTADOS_CANDIDATO, true)) {
                throw new DomainException("CHEQUE_ESTADO_INVALIDO: #{$cheque->id} está {$cheque->estado}.");
            }
            $vinculado = DB::table('erp_movimientos_bancarios_cheques')
                ->where('cheque_recibido_id', $cheque->id)->exists();
            if ($vinculado) {
                throw new DomainException("CHEQUE_YA_VINCULADO: #{$cheque->id} ya está vinculado a otro movimiento.");
            }
            $aux = $cheque->recibo_id
                ? DB::table('erp_recibos')->where('id', $cheque->recibo_id)->value('cliente_auxiliar_id')
                : null;
            if (! $aux) {
                throw new DomainException("CHEQUE_SIN_CLIENTE: #{$cheque->id} no tiene recibo/cliente asociado (1.1.4.04 exige auxiliar).");
            }
            $items[] = ['cheque' => $cheque, 'monto' => round((float) $c['monto'], 2), 'auxiliar_id' => (int) $aux];
        }

        return DB::transaction(function () use ($mov, $items, $observaciones, $usuario, $empresaId, $diarioId, $cuentaBanco, $ctaValoresId, $montoMov) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $ccGeneral = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
            $admiteCc = fn (int $id) => (bool) DB::table('erp_cuentas_contables')->where('id', $id)->value('admite_cc');
            $glosa = sprintf('Cobro cheques en cámara — mov #%d (%d cheque%s)', $mov->id, count($items), count($items) === 1 ? '' : 's');

            $movs = [[
                'cuenta_id' => (int) $cuentaBanco->cuenta_contable_id,
                'centro_costo_id' => $admiteCc((int) $cuentaBanco->cuenta_contable_id) ? $ccGeneral : null,
                'debe' => $montoMov, 'haber' => 0, 'glosa' => $glosa,
            ]];
            foreach ($items as $it) {
                $movs[] = [
                    'cuenta_id' => $ctaValoresId,
                    'centro_costo_id' => $admiteCc($ctaValoresId) ? $ccGeneral : null,
                    'auxiliar_id' => $it['auxiliar_id'],
                    'debe' => 0, 'haber' => $it['monto'],
                    'glosa' => 'Acreditación cheque #'.$it['cheque']->numero_cheque,
                ];
            }
            // La tolerancia $1 puede dejar una diferencia de centavos: se absorbe
            // en la última línea para que el asiento cuadre exacto.
            $dif = round($montoMov - array_sum(array_map(fn ($it) => $it['monto'], $items)), 2);
            if (abs($dif) > 0.001) {
                $last = count($movs) - 1;
                $movs[$last]['haber'] = round($movs[$last]['haber'] + $dif, 2);
            }

            $asiento = $this->asientos->crearBorrador([
                'empresa_id' => $empresaId, 'diario_id' => $diarioId,
                'fecha' => $mov->fecha->toDateString(),
                'glosa' => $glosa, 'origen' => 'BANCO',
                'origen_tabla' => 'erp_movimientos_bancarios', 'origen_id' => $mov->id,
                'observaciones' => $observaciones,
                'usuario_id' => $usuario->id, 'movimientos' => $movs,
            ]);
            $asiento = $this->asientos->contabilizar($asiento);

            $nros = [];
            foreach ($items as $it) {
                $cheque = $it['cheque'];
                DB::table('erp_movimientos_bancarios_cheques')->insert([
                    'mov_bancario_id' => $mov->id,
                    'cheque_recibido_id' => $cheque->id,
                    'monto_imputado' => $it['monto'],
                    'cheque_estado_previo' => $cheque->estado,
                    'created_by' => $usuario->id,
                    'created_at' => now(),
                ]);
                // D-49-1: los pendientes pasan a COBRADO al confirmar.
                if ($cheque->estado !== ChequeRecibido::ESTADO_COBRADO) {
                    $cheque->update([
                        'estado' => ChequeRecibido::ESTADO_COBRADO,
                        'fecha_acreditacion' => $mov->fecha->toDateString(),
                        'fecha_deposito' => $cheque->fecha_deposito ?: $mov->fecha->toDateString(),
                        'cuenta_bancaria_deposito_id' => $cheque->cuenta_bancaria_deposito_id ?: $mov->cuenta_bancaria_id,
                    ]);
                }
                $nros[] = '#'.$cheque->numero_cheque;
            }

            Conciliacion::create([
                'movimiento_bancario_id' => $mov->id,
                'referencia_tipo' => Conciliacion::REF_ASIENTO_MANUAL,
                'referencia_id' => $asiento->id,
                'importe_conciliado' => $montoMov,
                'user_id' => $usuario->id,
                'modo' => Conciliacion::MODO_MANUAL,
                'observacion' => 'v1.49 cheques cobrados: '.implode(', ', $nros),
            ]);

            $motivoId = DB::table('erp_conciliacion_motivos')->where('codigo', 'CHEQUE-COBRADO')->value('id');
            $mov->update([
                'estado' => MovimientoBancario::ESTADO_CONFIRMADO_CHEQUES,
                'asiento_id' => $asiento->id,
                'monto_conciliado' => $montoMov,
                'motivo_diferencia_id' => $motivoId,
                'observacion' => trim(($mov->observacion ? $mov->observacion.' · ' : '')
                    .'Cheques '.implode(', ', $nros).($observaciones ? ' · '.$observaciones : '')),
            ]);

            $this->audit->logEvento(
                accion: 'MOV_CONCILIADO_CHEQUES',
                modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d conciliado contra %d cheque(s) %s por $%s (asiento #%d)',
                    $mov->id, count($items), implode(', ', $nros), number_format($montoMov, 2, ',', '.'), $asiento->id),
                empresaId: $empresaId,
            );

            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    /**
     * Reversa (§4.3): anula el asiento, elimina las vinculaciones y devuelve
     * cada cheque a su estado previo. Llamada desde
     * ConciliacionService::desconciliar cuando el estado es
     * CONFIRMADO_CHEQUES_COBRADOS (corre dentro de su transacción).
     */
    public function revertir(MovimientoBancario $mov, User $usuario): void
    {
        $links = DB::table('erp_movimientos_bancarios_cheques')
            ->where('mov_bancario_id', $mov->id)->get();

        foreach ($links as $l) {
            // Volver al estado previo SOLO si el cheque fue cobrado por esta
            // conciliación (si ya estaba COBRADO de antes, queda igual).
            if ($l->cheque_estado_previo !== ChequeRecibido::ESTADO_COBRADO) {
                DB::table('erp_cheques_recibidos')->where('id', $l->cheque_recibido_id)->update([
                    'estado' => $l->cheque_estado_previo,
                    'fecha_acreditacion' => null,
                    'updated_at' => now(),
                ]);
            }
        }
        DB::table('erp_movimientos_bancarios_cheques')->where('mov_bancario_id', $mov->id)->delete();
    }
}
