<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\Conciliacion;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\AsientoService;
use App\Erp\Services\ConciliacionService;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * v1.45 §8 — Flujo Modificar / Confirmar / Revertir de imputaciones automáticas.
 *
 * Modelo (más seguro que el del addendum literal): el MATCH_AUTO solo PROPONE.
 * El asiento contable se genera al CONFIRMAR. Revertir anula el asiento (si lo
 * hay) y deja REVERTIDO. Esto respeta D-45-7 (humano siempre revisa antes de
 * impactar los libros).
 */
class ImputacionAutoService
{
    public function __construct(
        private readonly ConciliacionService $conciliacion,
        private readonly AuditLogger $audit,
    ) {}

    /** Cambia la factura imputada de un movimiento en MATCH_AUTO. */
    public function modificar(MovimientoBancario $mov, ?int $facturaId, ?string $tipoFactura, string $motivo, User $usuario): MovimientoBancario
    {
        if ($mov->estado !== MovimientoBancario::ESTADO_MATCH_AUTO) {
            throw new DomainException("ESTADO_INVALIDO: solo se modifica imputación en MATCH_AUTO (actual {$mov->estado}).");
        }
        if (strlen(trim($motivo)) < 5) throw new DomainException('MOTIVO_CORTO: mínimo 5 caracteres.');
        if ($facturaId && ! in_array($tipoFactura, ['VENTA', 'COMPRA'], true)) {
            throw new DomainException('TIPO_FACTURA_INVALIDO');
        }

        $previa = $mov->factura_imputada_id;
        DB::transaction(function () use ($mov, $facturaId, $tipoFactura, $motivo, $usuario, $previa) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);
            $mov->update([
                'factura_imputada_id' => $facturaId,
                'factura_imputada_tipo' => $facturaId ? $tipoFactura : null,
            ]);
            $this->auditFila($mov, 'MODIFICAR', $usuario->id, 'MATCH_AUTO', 'MATCH_AUTO', $previa, $facturaId, null, null, $motivo);
        });
        return $mov->fresh();
    }

    /** MATCH_AUTO → CONFIRMADO. Genera el asiento contable de cobro/pago. */
    public function confirmar(MovimientoBancario $mov, User $usuario): MovimientoBancario
    {
        if ($mov->estado !== MovimientoBancario::ESTADO_MATCH_AUTO) {
            throw new DomainException("ESTADO_INVALIDO: solo se confirma desde MATCH_AUTO (actual {$mov->estado}).");
        }
        if (! $mov->factura_imputada_id || ! $mov->factura_imputada_tipo) {
            throw new DomainException('SIN_FACTURA: no hay factura imputada para confirmar. Modificá la imputación primero o conciliá manualmente.');
        }

        $estadoPrevio = $mov->estado;
        $monto = (float) max($mov->debito, $mov->credito);

        // Reutiliza la lógica de asiento de conciliación contra factura.
        $mov = $this->conciliacion->conciliarContraFactura(
            $mov, $mov->factura_imputada_tipo, (int) $mov->factura_imputada_id, $monto, $usuario,
            motivo: 'Confirmación imputación automática v1.45',
        );

        $asientoId = $mov->asiento_id;
        DB::transaction(function () use ($mov, $usuario, $estadoPrevio, $asientoId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);
            $mov->update(['estado' => MovimientoBancario::ESTADO_CONFIRMADO]);
            $this->auditFila($mov, 'CONFIRMAR', $usuario->id, $estadoPrevio, 'CONFIRMADO',
                $mov->factura_imputada_id, $mov->factura_imputada_id, null, $asientoId, null);
        });
        return $mov->fresh();
    }

    /** Revierte una imputación MATCH_AUTO o CONFIRMADA. */
    public function revertir(MovimientoBancario $mov, string $motivo, User $usuario): MovimientoBancario
    {
        if (! in_array($mov->estado, [MovimientoBancario::ESTADO_MATCH_AUTO, MovimientoBancario::ESTADO_CONFIRMADO], true)) {
            throw new DomainException("ESTADO_INVALIDO: solo se revierte MATCH_AUTO o CONFIRMADO (actual {$mov->estado}).");
        }
        if (strlen(trim($motivo)) < 10) throw new DomainException('MOTIVO_CORTO: mínimo 10 caracteres.');

        // Bloqueo: factura en período cerrado (proxy de F.8001 exportado).
        $this->validarPeriodoAbierto($mov);

        $estadoPrevio = $mov->estado;
        $asientoPrevio = $mov->asiento_id;

        DB::transaction(function () use ($mov, $motivo, $usuario, $estadoPrevio, $asientoPrevio) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            // Anular asiento (si CONFIRMADO ya lo generó) + limpiar conciliaciones.
            if ($mov->asiento_id) {
                $asientoSvc = app(AsientoService::class);
                $asiento = Asiento::find($mov->asiento_id);
                if ($asiento && $asiento->estado === Asiento::ESTADO_CONTABILIZADO) {
                    $asientoSvc->anular($asiento, $usuario->id, "Reversión imputación auto mov #{$mov->id}: {$motivo}");
                }
            }
            Conciliacion::where('movimiento_bancario_id', $mov->id)->delete();

            $mov->update([
                'estado' => MovimientoBancario::ESTADO_REVERTIDO,
                'asiento_id' => null,
                'monto_conciliado' => 0,
                'observacion' => trim(($mov->observacion ?? '') . ' · REVERTIDO v1.45: ' . $motivo),
            ]);

            $this->auditFila($mov, 'REVERTIR', $usuario->id, $estadoPrevio, 'REVERTIDO',
                $mov->factura_imputada_id, $mov->factura_imputada_id, $asientoPrevio, null, $motivo);
        });
        return $mov->fresh();
    }

    private function validarPeriodoAbierto(MovimientoBancario $mov): void
    {
        if (! $mov->factura_imputada_id || ! $mov->factura_imputada_tipo) return;
        $tabla = $mov->factura_imputada_tipo === 'VENTA' ? 'erp_facturas_venta' : 'erp_facturas_compra';
        $periodoId = DB::table($tabla)->where('id', $mov->factura_imputada_id)->value('periodo_id');
        if (! $periodoId) return;
        $periodo = DB::table('erp_periodos')->where('id', $periodoId)->first(['anio', 'mes', 'estado']);
        if ($periodo && in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            throw new DomainException(sprintf(
                'PERIODO_CERRADO: la factura imputada está en el período %02d/%d (%s), que ya fue cerrado/exportado al F.8001. Para revertir, reabrir el período primero.',
                $periodo->mes, $periodo->anio, $periodo->estado,
            ));
        }
    }

    private function auditFila(MovimientoBancario $mov, string $accion, int $userId, ?string $previo, string $posterior, ?int $facPrev, ?int $facNueva, ?int $asientoPrev, ?int $asientoNuevo, ?string $motivo): void
    {
        DB::table('erp_extractos_imputaciones_audit')->insert([
            'movimiento_id' => $mov->id,
            'accion' => $accion,
            'user_id' => $userId,
            'estado_previo' => $previo,
            'estado_posterior' => $posterior,
            'factura_imputada_previa_id' => $facPrev,
            'factura_imputada_nueva_id' => $facNueva,
            'asiento_previo_id' => $asientoPrev,
            'asiento_nuevo_id' => $asientoNuevo,
            'motivo' => $motivo,
            'snapshot_completo' => json_encode([
                'mov_id' => $mov->id, 'concepto' => $mov->concepto,
                'monto' => (float) max($mov->debito, $mov->credito),
                'cuit_extractado' => $mov->cuit_extractado,
                'auxiliar_resuelto_id' => $mov->auxiliar_resuelto_id,
                'confianza' => $mov->imputacion_confianza,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
