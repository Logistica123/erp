<?php

namespace App\Erp\Services;

use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase C — Inversiones (FCI + Plazos Fijos + Cauciones + Bonos).
 *
 * Reglas de saldo + ganancia (saldo_actual y ganancia_acumulada se mantienen
 * cached en erp_inversiones y se recalculan al registrar un movimiento):
 *
 *  - SUSCRIPCION:  saldo += importe                                      (entra plata)
 *  - RESCATE:      saldo -= importe                                      (sale plata)
 *  - CONSTITUCION: saldo += importe                                      (alta de PF)
 *  - VENCIMIENTO:  saldo -= importe (capital + intereses devengados)     (PF cierra)
 *  - INTERES:      saldo += importe; ganancia += importe                 (PF devenga)
 *  - AJUSTE_SALDO_FONDO: saldo := saldo_segun_fondo;                     (FCI reconcile)
 *                        delta := saldo_nuevo - saldo_anterior;
 *                        ganancia += delta
 */
class InversionesService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function listar(int $empresaId, ?string $tipo = null, ?bool $activo = null): array
    {
        $q = DB::table('erp_inversiones')
            ->where('empresa_id', $empresaId)
            ->orderBy('activo', 'desc')->orderBy('nombre');
        if ($tipo) $q->where('tipo', $tipo);
        if ($activo !== null) $q->where('activo', $activo);
        return $q->get()->all();
    }

    /**
     * @param  array{
     *   empresa_id:int, nombre:string, tipo:string, entidad:string, moneda?:string,
     *   cuenta_contable_id?:?int, fecha_alta:string, plazo_dias?:?int, tasa_nominal?:?float,
     *   fecha_vencimiento?:?string, usuario_id:int
     * }  $data
     */
    public function crear(array $data): int
    {
        $exists = DB::table('erp_inversiones')
            ->where('empresa_id', $data['empresa_id'])
            ->where('nombre', $data['nombre'])
            ->exists();
        if ($exists) throw new DomainException("INVERSION_DUPLICADA: ya existe '{$data['nombre']}'.");

        // Para PF/Caución, si vino plazo_dias y fecha_alta, calcular fecha_vencimiento si no vino.
        $venc = $data['fecha_vencimiento'] ?? null;
        if (! $venc && ($data['plazo_dias'] ?? null) && in_array($data['tipo'], ['PLAZO_FIJO', 'CAUCION'], true)) {
            $venc = Carbon::parse($data['fecha_alta'])->addDays((int) $data['plazo_dias'])->toDateString();
        }

        $id = DB::table('erp_inversiones')->insertGetId([
            'empresa_id' => $data['empresa_id'],
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'entidad' => $data['entidad'],
            'moneda' => $data['moneda'] ?? 'ARS',
            'cuenta_contable_id' => $data['cuenta_contable_id'] ?? null,
            'fecha_alta' => $data['fecha_alta'],
            'plazo_dias' => $data['plazo_dias'] ?? null,
            'tasa_nominal' => $data['tasa_nominal'] ?? null,
            'fecha_vencimiento' => $venc,
            'saldo_actual' => 0,
            'ganancia_acumulada' => 0,
            'activo' => true,
        ]);

        $this->audit->logEvento(
            accion: 'INVERSION_CREADA',
            modulo: 'tesoreria',
            descripcion: sprintf('Inversión creada #%d: %s (%s) entidad=%s',
                $id, $data['nombre'], $data['tipo'], $data['entidad']),
            empresaId: $data['empresa_id'],
        );
        return $id;
    }

    public function movimientos(int $inversionId): array
    {
        return DB::table('erp_inversiones_movimientos as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.registrado_por_user_id')
            ->where('m.inversion_id', $inversionId)
            ->orderBy('m.fecha')->orderBy('m.id')
            ->select(['m.*', 'u.name as registrado_por_nombre'])
            ->get()->all();
    }

    /**
     * @param  array{
     *   tipo:string, fecha:string, importe:float|string,
     *   saldo_segun_fondo?:?float, cuenta_bancaria_id?:?int,
     *   observaciones?:?string, usuario_id:int
     * }  $data
     */
    public function registrarMovimiento(int $inversionId, array $data): int
    {
        $inv = DB::table('erp_inversiones')->find($inversionId);
        if (! $inv) throw new DomainException("INVERSION_NO_ENCONTRADA");
        if (! $inv->activo && $data['tipo'] !== 'AJUSTE_SALDO_FONDO') {
            throw new DomainException("INVERSION_INACTIVA: no se puede registrar movimientos en una inversión dada de baja.");
        }

        $tipo = $data['tipo'];
        $importe = round((float) $data['importe'], 2);
        if ($importe < 0) throw new DomainException("IMPORTE_NEGATIVO: usar el tipo de movimiento adecuado (RESCATE/VENCIMIENTO bajan saldo).");

        return DB::transaction(function () use ($inv, $inversionId, $tipo, $importe, $data) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $saldoAnterior = (float) $inv->saldo_actual;
            $gananciaAnterior = (float) $inv->ganancia_acumulada;

            $saldoNuevo = $saldoAnterior;
            $gananciaNueva = $gananciaAnterior;

            switch ($tipo) {
                case 'SUSCRIPCION':
                case 'CONSTITUCION':
                    $saldoNuevo += $importe;
                    break;
                case 'RESCATE':
                case 'VENCIMIENTO':
                    if ($importe > $saldoAnterior + 0.01) {
                        throw new DomainException(sprintf('SALDO_INSUFICIENTE: pide rescatar/vencer $%.2f pero el saldo es $%.2f', $importe, $saldoAnterior));
                    }
                    $saldoNuevo -= $importe;
                    break;
                case 'INTERES':
                    $saldoNuevo += $importe;
                    $gananciaNueva += $importe;
                    break;
                case 'AJUSTE_SALDO_FONDO':
                    if (! isset($data['saldo_segun_fondo'])) {
                        throw new DomainException("SALDO_SEGUN_FONDO_REQUERIDO: especificar el saldo informado por el fondo.");
                    }
                    $saldoNuevo = round((float) $data['saldo_segun_fondo'], 2);
                    $delta = round($saldoNuevo - $saldoAnterior, 2);
                    $gananciaNueva += $delta;
                    // El importe del movimiento queda como el delta para trazabilidad.
                    $importe = abs($delta);
                    break;
                default:
                    throw new DomainException("TIPO_INVALIDO: {$tipo}");
            }

            $movId = DB::table('erp_inversiones_movimientos')->insertGetId([
                'inversion_id' => $inversionId,
                'fecha' => $data['fecha'],
                'tipo' => $tipo,
                'importe' => $importe,
                'saldo_segun_rys' => $saldoNuevo,
                'saldo_segun_fondo' => $data['saldo_segun_fondo'] ?? null,
                'cuenta_bancaria_id' => $data['cuenta_bancaria_id'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'registrado_por_user_id' => $data['usuario_id'],
            ]);

            DB::table('erp_inversiones')->where('id', $inversionId)->update([
                'saldo_actual' => $saldoNuevo,
                'ganancia_acumulada' => $gananciaNueva,
            ]);

            // VENCIMIENTO en PF da de baja la inversión.
            if ($tipo === 'VENCIMIENTO' && in_array($inv->tipo, ['PLAZO_FIJO', 'CAUCION'], true) && $saldoNuevo < 0.01) {
                DB::table('erp_inversiones')->where('id', $inversionId)->update([
                    'activo' => false,
                    'fecha_baja' => $data['fecha'],
                ]);
            }

            $this->audit->logEvento(
                accion: 'INVERSION_MOVIMIENTO',
                modulo: 'tesoreria',
                descripcion: sprintf('Inversión #%d %s: $%.2f · saldo %.2f→%.2f · ganancia +%.2f',
                    $inversionId, $tipo, $importe, $saldoAnterior, $saldoNuevo, $gananciaNueva - $gananciaAnterior),
                empresaId: $inv->empresa_id,
            );

            return $movId;
        });
    }

    public function totales(int $empresaId): array
    {
        $rows = DB::table('erp_inversiones')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->select([
                DB::raw('SUM(saldo_actual) as saldo_total'),
                DB::raw('SUM(ganancia_acumulada) as ganancia_total'),
            ])->first();
        return [
            'saldo_total' => (float) ($rows->saldo_total ?? 0),
            'ganancia_total' => (float) ($rows->ganancia_total ?? 0),
        ];
    }
}
