<?php

namespace App\Erp\Services\Contabilidad;

use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 §9 — Reclasificación del Impuesto Ley 25413 (D-45-1).
 *
 * Día a día el impuesto se registra 100% al gasto 5.4.04. Al cierre (trimestral
 * / anual), el contador reclasifica el % computable como crédito fiscal:
 *   Db 1.1.6.12 Impuesto Débitos y Créditos a Computar
 *   Cr 5.4.04   Impuesto sobre Débitos y Créditos Bancarios
 */
class ReclasificacionIiddyccService
{
    private const CUENTA_GASTO = '5.4.04';
    private const CUENTA_CREDITO = '1.1.6.12';
    private const EMPRESA_ID = 1;

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /** Saldo deudor acumulado de la cuenta de gasto entre fechas (contabilizado). */
    public function saldoAcumulado(string $desde, string $hasta, int $empresaId = self::EMPRESA_ID): array
    {
        $cuentaId = $this->cuentaId(self::CUENTA_GASTO, $empresaId);
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)
            ->where('m.cuenta_id', $cuentaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(m.debe),0) deb, COALESCE(SUM(m.haber),0) hab')
            ->first();
        $saldo = round((float) $row->deb - (float) $row->hab, 2);
        return [
            'cuenta_gasto' => self::CUENTA_GASTO,
            'desde' => $desde, 'hasta' => $hasta,
            'saldo' => $saldo,
        ];
    }

    /**
     * Genera el asiento de reclasificación por el % indicado.
     *
     * @param  array{desde:string, hasta:string, porcentaje:float, fecha?:string, observaciones?:?string, usuario_id:int}  $data
     */
    public function generar(array $data, int $empresaId = self::EMPRESA_ID): \App\Erp\Models\Asiento
    {
        $pct = (float) $data['porcentaje'];
        if ($pct <= 0 || $pct > 100) throw new DomainException('PORCENTAJE_INVALIDO: debe estar entre 0 y 100.');

        $saldoInfo = $this->saldoAcumulado($data['desde'], $data['hasta'], $empresaId);
        $saldo = $saldoInfo['saldo'];
        if ($saldo <= 0.01) {
            throw new DomainException(sprintf('SIN_SALDO: la cuenta %s no tiene saldo deudor en el período (saldo $%.2f).', self::CUENTA_GASTO, $saldo));
        }
        $importe = round($saldo * $pct / 100, 2);
        if ($importe <= 0.01) throw new DomainException('IMPORTE_CERO: el monto a reclasificar es 0.');

        $fecha = $data['fecha'] ?? Carbon::parse($data['hasta'])->endOfMonth()->toDateString();
        $this->validarPeriodoAbierto($fecha, $empresaId);

        $diarioAju = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'AJU')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioAju) throw new DomainException('DIARIO_AJU_INEXISTENTE');

        $ctaCredito = $this->cuentaId(self::CUENTA_CREDITO, $empresaId);
        $ctaGasto = $this->cuentaId(self::CUENTA_GASTO, $empresaId);
        $glosa = sprintf('Reclasificación Imp Ley 25413 al %s%% — %s a %s', rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'), $data['desde'], $data['hasta']);

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id' => $diarioAju,
            'fecha' => $fecha,
            'glosa' => $glosa,
            'origen' => 'AJUSTE',
            'origen_tabla' => 'reclasificacion_iiddycc',
            'observaciones' => $data['observaciones'] ?? null,
            'usuario_id' => $data['usuario_id'],
            'movimientos' => [
                ['cuenta_id' => $ctaCredito, 'debe' => $importe, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $ctaGasto, 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
            ],
        ]);
        $asiento = $this->asientoService->contabilizar($asiento);

        $this->audit->logEvento(
            accion: 'RECLASIFICACION_IIDDYCC',
            modulo: 'contabilidad',
            descripcion: sprintf('Reclasificación Imp Ley 25413 %s%% sobre saldo $%.2f = $%.2f (asiento #%d) %s→%s',
                $pct, $saldo, $importe, $asiento->id, $data['desde'], $data['hasta']),
            empresaId: $empresaId,
        );
        return $asiento;
    }

    private function validarPeriodoAbierto(string $fecha, int $empresaId): void
    {
        $c = Carbon::parse($fecha);
        $periodo = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $empresaId)
            ->where('p.anio', $c->year)->where('p.mes', $c->month)
            ->first(['p.estado']);
        if ($periodo && in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            throw new DomainException(sprintf('PERIODO_CERRADO: %02d/%d está %s.', $c->month, $c->year, $periodo->estado));
        }
    }

    private function cuentaId(string $codigo, int $empresaId): int
    {
        $id = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', $codigo)->value('id');
        if (! $id) throw new DomainException("CUENTA_NO_ENCONTRADA: {$codigo}");
        return (int) $id;
    }
}
