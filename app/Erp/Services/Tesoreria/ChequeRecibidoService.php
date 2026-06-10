<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Tesoreria\ChequeRecibido;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChequeRecibidoService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crea un cheque asociado a un recibo emitido. Llamado por ReciboService
     * cuando el medio de cobro es `CHEQUES_CARTERA`.
     *
     * @param  array{
     *   numero_cheque:string, banco_emisor:string, cuit_librador?:?string,
     *   librador_nombre?:?string, fecha_emision:string, fecha_pago:string,
     *   importe:float|string, observaciones?:?string,
     * }  $data
     */
    public function crearDesdeRecibo(int $reciboId, int $empresaId, array $data, int $userId): ChequeRecibido
    {
        foreach (['numero_cheque', 'banco_emisor', 'fecha_emision', 'fecha_pago', 'importe'] as $k) {
            if (empty($data[$k])) {
                throw new DomainException("CHEQUE_CAMPO_REQUERIDO: {$k}");
            }
        }
        $cheque = ChequeRecibido::create([
            'empresa_id' => $empresaId,
            'recibo_id' => $reciboId,
            'numero_cheque' => trim((string) $data['numero_cheque']),
            'banco_emisor' => trim((string) $data['banco_emisor']),
            'cuit_librador' => ! empty($data['cuit_librador'])
                ? preg_replace('/[^0-9]/', '', (string) $data['cuit_librador'])
                : null,
            'librador_nombre' => $data['librador_nombre'] ?? null,
            'fecha_emision' => $data['fecha_emision'],
            'fecha_pago' => $data['fecha_pago'],
            'importe' => round((float) $data['importe'], 2),
            'estado' => ChequeRecibido::ESTADO_EN_CARTERA,
            'observaciones' => $data['observaciones'] ?? null,
            'created_by_user_id' => $userId,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_RECIBIDO',
            modulo: 'tesoreria',
            descripcion: sprintf('Cheque %s · banco %s · $%.2f · vto %s · recibo #%d',
                $cheque->numero_cheque, $cheque->banco_emisor, $cheque->importe,
                (string) $cheque->fecha_pago, $reciboId),
            empresaId: $empresaId,
        );
        return $cheque;
    }

    public function listar(array $filtros): array
    {
        $q = DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'c.cuenta_bancaria_deposito_id')
            ->select([
                'c.*',
                'r.numero_correlativo as recibo_numero',
                'a.nombre as cliente_nombre',
                'cb.nombre as cuenta_deposito_nombre',
            ])
            ->orderBy('c.fecha_pago')->orderByDesc('c.id');
        if (! empty($filtros['estado'])) $q->where('c.estado', $filtros['estado']);
        if (! empty($filtros['desde'])) $q->where('c.fecha_pago', '>=', $filtros['desde']);
        if (! empty($filtros['hasta'])) $q->where('c.fecha_pago', '<=', $filtros['hasta']);
        if (! empty($filtros['numero'])) $q->where('c.numero_cheque', 'like', '%' . $filtros['numero'] . '%');
        if (! empty($filtros['solo_vencidos_sin_cobrar'])) {
            $q->where('c.estado', ChequeRecibido::ESTADO_EN_CARTERA)
              ->where('c.fecha_pago', '<', today());
        }
        return $q->paginate((int) ($filtros['per_page'] ?? 50))->toArray();
    }

    public function alertasVencidos(): array
    {
        return DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('c.estado', ChequeRecibido::ESTADO_EN_CARTERA)
            ->where('c.fecha_pago', '<', today())
            ->orderBy('c.fecha_pago')
            ->select([
                'c.id', 'c.numero_cheque', 'c.banco_emisor', 'c.importe', 'c.fecha_pago',
                'a.nombre as cliente_nombre', 'r.numero_correlativo as recibo_numero',
                DB::raw('DATEDIFF(CURRENT_DATE, c.fecha_pago) as dias_vencido'),
            ])
            ->limit(100)
            ->get()
            ->all();
    }

    public function depositar(int $chequeId, int $cuentaBancariaId, string $fechaDeposito, int $userId, ?string $obs = null): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede depositar.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_DEPOSITADO,
            'cuenta_bancaria_deposito_id' => $cuentaBancariaId,
            'fecha_deposito' => $fechaDeposito,
            'observaciones' => $obs ?: $cheque->observaciones,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_DEPOSITADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} depositado en cuenta #{$cuentaBancariaId}",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    public function cobrar(int $chequeId, string $fechaAcreditacion, int $userId, ?int $movBancarioId = null): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_DEPOSITADO, ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede cobrar.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_COBRADO,
            'fecha_acreditacion' => $fechaAcreditacion,
            'mov_bancario_id' => $movBancarioId,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_COBRADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} cobrado/acreditado",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    public function rechazar(int $chequeId, string $motivo, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if ($cheque->estado === ChequeRecibido::ESTADO_COBRADO) {
            throw new DomainException("CHEQUE_YA_COBRADO: no se puede rechazar un cheque ya acreditado.");
        }
        if (strlen(trim($motivo)) < 5) {
            throw new DomainException("MOTIVO_CORTO: el motivo de rechazo debe tener al menos 5 caracteres.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_RECHAZADO,
            'fecha_rechazo' => today(),
            'motivo_rechazo' => trim($motivo),
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_RECHAZADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} rechazado: {$motivo}",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    /**
     * Marca como VENCIDO_NO_COBRADO los cheques EN_CARTERA con fecha_pago < hoy.
     * Para correr por cron diario. Devuelve la cantidad de cheques afectados.
     */
    public function marcarVencidos(): int
    {
        return DB::table('erp_cheques_recibidos')
            ->where('estado', ChequeRecibido::ESTADO_EN_CARTERA)
            ->where('fecha_pago', '<', today())
            ->update([
                'estado' => ChequeRecibido::ESTADO_VENCIDO,
                'updated_at' => now(),
            ]);
    }
}
