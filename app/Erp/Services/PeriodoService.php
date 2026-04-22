<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Periodo;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cierre de período:
 *  · Valida que no haya asientos en BORRADOR (RN pre-cierre).
 *  · Marca el período como CERRADO + registra usuario + fecha + hash.
 *  · Recompute final de saldos.
 *  · Dispara evento de audit_log con descripción.
 *
 * No genera asiento de refundición en esta versión — eso se hace al cerrar el
 * ejercicio completo (no el período intermedio).
 *
 * Reapertura requiere permiso distinto (`contabilidad.periodos.reabrir`),
 * solo super_admin.
 */
class PeriodoService
{
    public function __construct(
        private readonly SaldosService $saldosService,
        private readonly AuditLogger $audit,
    ) {}

    public function cerrar(Periodo $periodo, User $usuario): Periodo
    {
        if ($periodo->estado !== 'ABIERTO') {
            throw new DomainException('PERIODO_YA_CERRADO: estado actual '.$periodo->estado);
        }

        // RN pre-cierre: no puede haber BORRADORES del período.
        $borradores = Asiento::where('periodo_id', $periodo->id)
            ->where('estado', Asiento::ESTADO_BORRADOR)
            ->count();

        if ($borradores > 0) {
            throw new DomainException(
                "BORRADORES_PENDIENTES: el período tiene {$borradores} asiento(s) en BORRADOR. Contabilizalos o eliminalos antes de cerrar."
            );
        }

        return DB::transaction(function () use ($periodo, $usuario) {
            // Recompute final de saldos del período antes de sellar.
            $this->saldosService->recomputarPeriodo($periodo->id);

            // Propaga saldos patrimoniales al período siguiente como saldo_inicial.
            $propagadas = $this->saldosService->propagarSaldosAlSiguientePeriodo($periodo->id);

            // Hash de cierre: snapshot de los saldos + timestamp.
            $snapshot = DB::table('erp_saldos_cuenta')
                ->where('periodo_id', $periodo->id)
                ->orderBy('cuenta_id')
                ->get(['cuenta_id', 'debitos', 'creditos', 'saldo_final'])
                ->toArray();

            $hash = hash('sha256', json_encode([
                'periodo_id' => $periodo->id,
                'saldos' => $snapshot,
                'cerrado_en' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));

            $periodo->update([
                'estado' => 'CERRADO',
                'fecha_cierre' => now(),
                'usuario_cierre_id' => $usuario->id,
            ]);

            $this->audit->logEvento(
                accion: 'PERIODO_CERRADO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Cierre período %02d/%d · hash=%s · %d cuentas con movimiento · %d saldos propagados al período siguiente',
                    $periodo->mes,
                    $periodo->anio,
                    substr($hash, 0, 16),
                    count($snapshot),
                    $propagadas,
                ),
                empresaId: $periodo->ejercicio->empresa_id,
            );

            return $periodo->fresh();
        });
    }

    /**
     * Bloquea un período sin cerrarlo definitivamente. Se usa cuando se presentó
     * una DDJJ (IVA / IIBB) y no se quieren nuevos asientos sobre el período,
     * pero todavía se puede desbloquear sin privilegios de super_admin
     * (a diferencia de CERRADO → ABIERTO).
     */
    public function bloquear(Periodo $periodo, User $usuario, string $motivo): Periodo
    {
        if ($periodo->estado !== 'ABIERTO') {
            throw new DomainException('PERIODO_NO_ABIERTO: solo se bloquean períodos ABIERTOS (actual: '.$periodo->estado.')');
        }

        return DB::transaction(function () use ($periodo, $usuario, $motivo) {
            $periodo->update(['estado' => 'BLOQUEADO']);

            $this->audit->logEvento(
                accion: 'PERIODO_BLOQUEADO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Bloqueo período %02d/%d por %s · motivo: %s',
                    $periodo->mes,
                    $periodo->anio,
                    $usuario->name,
                    $motivo
                ),
                empresaId: $periodo->ejercicio->empresa_id,
            );

            return $periodo->fresh();
        });
    }

    public function desbloquear(Periodo $periodo, User $usuario, string $motivo): Periodo
    {
        if ($periodo->estado !== 'BLOQUEADO') {
            throw new DomainException('PERIODO_NO_BLOQUEADO: estado actual '.$periodo->estado);
        }

        return DB::transaction(function () use ($periodo, $usuario, $motivo) {
            $periodo->update(['estado' => 'ABIERTO']);

            $this->audit->logEvento(
                accion: 'PERIODO_DESBLOQUEADO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Desbloqueo período %02d/%d por %s · motivo: %s',
                    $periodo->mes,
                    $periodo->anio,
                    $usuario->name,
                    $motivo
                ),
                empresaId: $periodo->ejercicio->empresa_id,
            );

            return $periodo->fresh();
        });
    }

    public function reabrir(Periodo $periodo, User $usuario, string $motivo): Periodo
    {
        if ($periodo->estado !== 'CERRADO') {
            throw new DomainException('PERIODO_NO_CERRADO: estado actual '.$periodo->estado);
        }

        return DB::transaction(function () use ($periodo, $usuario, $motivo) {
            $periodo->update([
                'estado' => 'ABIERTO',
                'fecha_cierre' => null,
                'usuario_cierre_id' => null,
            ]);

            $this->audit->logEvento(
                accion: 'PERIODO_REABIERTO',
                modulo: 'ejercicios',
                descripcion: sprintf(
                    'Reapertura período %02d/%d por %s · motivo: %s',
                    $periodo->mes,
                    $periodo->anio,
                    $usuario->name,
                    $motivo
                ),
                empresaId: $periodo->ejercicio->empresa_id,
            );

            return $periodo->fresh();
        });
    }
}
