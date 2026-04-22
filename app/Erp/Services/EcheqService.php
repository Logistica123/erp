<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Models\Tesoreria\EcheqMovimiento;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Flujo del eCheq (SPEC 02 §7.5-7.6, RN-18, RN-19):
 *   EN_CARTERA → DEPOSITADO → ACREDITADO
 *              → RECHAZADO (desde DEPOSITADO)
 *              → ANULADO   (desde EN_CARTERA)
 *
 * RN-18: al ACREDITADO genera asiento automático
 *   DEBE cuenta bancaria receptora · HABER 1.1.1.04 Valores a Depositar
 *
 * RN-19: al RECHAZADO genera asiento reversa proporcional del cobro
 *   DEBE deudores por ventas (cliente) · HABER 1.1.1.04 Valores a Depositar
 *   y reabre el cobro asociado como RECHAZADO_PARCIAL o RECHAZADO según
 *   si tenía otros medios o era único.
 *
 * Un trigger SQL (trg_echeq_historial_au) registra en erp_echeq_movimientos
 * cada cambio de estado.
 */
class EcheqService
{
    public const CODIGO_CUENTA_VALORES = '1.1.1.04';

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * EN_CARTERA → DEPOSITADO. Indica la cuenta bancaria destino y fecha.
     */
    public function depositar(Echeq $echeq, int $cuentaBancariaId, User $usuario): Echeq
    {
        if ($echeq->estado !== Echeq::ESTADO_EN_CARTERA) {
            throw new DomainException('ECHEQ_ESTADO_INVALIDO: solo se deposita desde EN_CARTERA (actual: '.$echeq->estado.')');
        }

        $cuenta = CuentaBancaria::where('empresa_id', $echeq->empresa_id)
            ->where('id', $cuentaBancariaId)
            ->firstOrFail();

        return DB::transaction(function () use ($echeq, $cuenta, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $echeq->update([
                'estado' => Echeq::ESTADO_DEPOSITADO,
                'deposito_cuenta_id' => $cuenta->id,
                'fecha_deposito' => now(),
            ]);

            $this->audit->logEvento(
                accion: 'ECHEQ_DEPOSITADO',
                modulo: 'tesoreria',
                descripcion: sprintf('eCheq %s depositado en %s por %s', $echeq->numero, $cuenta->codigo, $usuario->name),
                empresaId: $echeq->empresa_id,
            );

            return $echeq->fresh();
        });
    }

    /**
     * DEPOSITADO → ACREDITADO + asiento automático (RN-18).
     * Recibe el movimiento bancario del extracto que confirma la acreditación.
     */
    public function acreditar(Echeq $echeq, int $movimientoBancarioId, User $usuario): Echeq
    {
        if ($echeq->estado !== Echeq::ESTADO_DEPOSITADO) {
            throw new DomainException('ECHEQ_ESTADO_INVALIDO: solo se acredita desde DEPOSITADO (actual: '.$echeq->estado.')');
        }

        $mov = MovimientoBancario::findOrFail($movimientoBancarioId);
        if (! $echeq->deposito_cuenta_id || $mov->cuenta_bancaria_id !== $echeq->deposito_cuenta_id) {
            throw new DomainException('ECHEQ_CUENTA_MISMATCH: el movimiento no es de la cuenta de depósito');
        }

        return DB::transaction(function () use ($echeq, $mov, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $asiento = $this->asientoAcreditacion($echeq, $mov, $usuario);

            $echeq->update([
                'estado' => Echeq::ESTADO_ACREDITADO,
                'movimiento_bancario_id' => $mov->id,
                'fecha_acreditacion' => $mov->fecha,
            ]);

            // Si el cobro tenía estado REGISTRADO y ya no hay medios
            // pendientes, pasamos a ACREDITADO. Si quedan medios pendientes,
            // PARCIAL_ACREDITADO.
            $this->actualizarEstadoCobro($echeq);

            $this->audit->logEvento(
                accion: 'ECHEQ_ACREDITADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'eCheq %s acreditado · asiento %d · mov %d',
                    $echeq->numero,
                    $asiento->id,
                    $mov->id
                ),
                empresaId: $echeq->empresa_id,
            );

            return $echeq->fresh(['cobro']);
        });
    }

    /**
     * DEPOSITADO → RECHAZADO + asiento reversa del cobro proporcional (RN-19).
     * Reabre el cobro asociado según tenga otros medios o no.
     */
    public function rechazar(Echeq $echeq, string $motivo, User $usuario): Echeq
    {
        if ($echeq->estado !== Echeq::ESTADO_DEPOSITADO) {
            throw new DomainException('ECHEQ_ESTADO_INVALIDO: solo se rechaza desde DEPOSITADO (actual: '.$echeq->estado.')');
        }

        return DB::transaction(function () use ($echeq, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $asiento = $this->asientoReversa($echeq, $motivo, $usuario);

            $echeq->update([
                'estado' => Echeq::ESTADO_RECHAZADO,
                'motivo_rechazo' => $motivo,
            ]);

            // Marcar el cobro_medio correspondiente como RECHAZADO y
            // reescalar estado del cobro global (RECHAZADO vs RECHAZADO_PARCIAL).
            $this->rechazarMedioCobro($echeq);

            $this->audit->logEvento(
                accion: 'ECHEQ_RECHAZADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'eCheq %s rechazado: %s · asiento reversa %d',
                    $echeq->numero,
                    $motivo,
                    $asiento->id
                ),
                empresaId: $echeq->empresa_id,
            );

            return $echeq->fresh(['cobro']);
        });
    }

    /**
     * EN_CARTERA → ANULADO. No genera asiento porque el cobro original
     * ya tiene contabilizado el eCheq contra 1.1.1.04. Solo marca estado
     * (el cobro se revierte por otro flujo si hace falta).
     */
    public function anular(Echeq $echeq, string $motivo, User $usuario): Echeq
    {
        if ($echeq->estado !== Echeq::ESTADO_EN_CARTERA) {
            throw new DomainException('ECHEQ_ESTADO_INVALIDO: solo se anula desde EN_CARTERA (actual: '.$echeq->estado.')');
        }

        return DB::transaction(function () use ($echeq, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $echeq->update([
                'estado' => Echeq::ESTADO_ANULADO,
                'motivo_rechazo' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'ECHEQ_ANULADO',
                modulo: 'tesoreria',
                descripcion: sprintf('eCheq %s anulado: %s', $echeq->numero, $motivo),
                empresaId: $echeq->empresa_id,
            );

            return $echeq->fresh();
        });
    }

    /**
     * Asiento RN-18: DEBE cuenta bancaria receptora · HABER 1.1.1.04 Valores a Depositar.
     * Se contabiliza en el diario BAN con origen = MovimientoBancario asociado.
     */
    private function asientoAcreditacion(Echeq $echeq, MovimientoBancario $mov, User $usuario): Asiento
    {
        $cuentaBancaria = CuentaBancaria::with('moneda')->findOrFail($echeq->deposito_cuenta_id);
        $cuentaContableBanco = $cuentaBancaria->cuenta_contable_id;
        $cuentaValores = $this->cuentaValoresADepositar($echeq->empresa_id);

        $diarioBan = DB::table('erp_diarios')
            ->where('empresa_id', $echeq->empresa_id)
            ->where('codigo', 'BAN')
            ->value('id');

        $importe = (float) $echeq->importe;
        $glosa = sprintf('Acreditación eCheq %s · %s', $echeq->numero, $echeq->razon_social_librador ?? $echeq->cuit_librador);

        $movimientos = $this->completarCc([
            ['cuenta_id' => $cuentaContableBanco, 'debe' => $importe, 'haber' => 0, 'glosa' => $glosa],
            ['cuenta_id' => $cuentaValores->id, 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
        ], $echeq->empresa_id);

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $echeq->empresa_id,
            'diario_id' => $diarioBan,
            'fecha' => $mov->fecha->toDateString(),
            'glosa' => $glosa,
            'origen' => 'BANCO',
            'origen_id' => $echeq->id,
            'origen_tabla' => 'erp_echeq',
            'usuario_id' => $usuario->id,
            'movimientos' => $movimientos,
        ]);

        return $this->asientoService->contabilizar($asiento);
    }

    /**
     * Asiento RN-19: DEBE deudores por ventas (cliente) · HABER 1.1.1.04.
     * Revierte el crédito a "Valores a Depositar" que generó el cobro.
     */
    private function asientoReversa(Echeq $echeq, string $motivo, User $usuario): Asiento
    {
        $cobro = $echeq->cobro;
        if (! $cobro) {
            throw new DomainException('ECHEQ_SIN_COBRO: no hay cobro asociado al eCheq para revertir');
        }

        $cuentaValores = $this->cuentaValoresADepositar($echeq->empresa_id);
        // Cuenta contable del cliente (auxiliar): usa 1.1.4.01 Deudores por Ventas
        // como convención (SPEC: los cobros contra clientes van contra esa cuenta).
        $cuentaDeudores = CuentaContable::where('empresa_id', $echeq->empresa_id)
            ->where('codigo', '1.1.4.01')
            ->first();
        if (! $cuentaDeudores) {
            throw new DomainException('CUENTA_CONTABLE_NO_ENCONTRADA: 1.1.4.01 Deudores por Ventas');
        }

        $diarioAju = DB::table('erp_diarios')
            ->where('empresa_id', $echeq->empresa_id)
            ->where('codigo', 'AJU')
            ->value('id');

        $importe = (float) $echeq->importe;
        $glosa = sprintf('Reversa eCheq %s (rechazado): %s', $echeq->numero, $motivo);

        $movimientos = $this->completarCc([
            [
                'cuenta_id' => $cuentaDeudores->id,
                'debe' => $importe,
                'haber' => 0,
                'glosa' => $glosa,
                'auxiliar_id' => $cuentaDeudores->admite_auxiliar ? $cobro->auxiliar_id : null,
            ],
            ['cuenta_id' => $cuentaValores->id, 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
        ], $echeq->empresa_id);

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $echeq->empresa_id,
            'diario_id' => $diarioAju,
            'fecha' => now()->toDateString(),
            'glosa' => $glosa,
            'origen' => 'AJUSTE',
            'origen_id' => $echeq->id,
            'origen_tabla' => 'erp_echeq',
            'usuario_id' => $usuario->id,
            'movimientos' => $movimientos,
        ]);

        return $this->asientoService->contabilizar($asiento);
    }

    /**
     * Completa centro_costo_id en las líneas cuya cuenta contable admite_cc=1
     * y no lo traen explícito, usando CC CENTRAL como fallback.
     *
     * @param  array<int, array<string, mixed>>  $movs
     * @return array<int, array<string, mixed>>
     */
    private function completarCc(array $movs, int $empresaId): array
    {
        $ccFallback = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'CENTRAL')
            ->value('id');

        foreach ($movs as &$m) {
            if (empty($m['centro_costo_id'])) {
                $cuenta = CuentaContable::find($m['cuenta_id']);
                if ($cuenta && $cuenta->admite_cc && $ccFallback) {
                    $m['centro_costo_id'] = (int) $ccFallback;
                }
            }
        }

        return $movs;
    }

    private function cuentaValoresADepositar(int $empresaId): CuentaContable
    {
        $cuenta = CuentaContable::where('empresa_id', $empresaId)
            ->where('codigo', self::CODIGO_CUENTA_VALORES)
            ->first();
        if (! $cuenta) {
            throw new DomainException('CUENTA_CONTABLE_NO_ENCONTRADA: '.self::CODIGO_CUENTA_VALORES.' Valores a Depositar');
        }

        return $cuenta;
    }

    /**
     * Recalcula el estado del cobro asociado al eCheq tras una acreditación.
     * Si todos los medios están ACREDITADOS → cobro ACREDITADO.
     * Si al menos uno sigue PENDIENTE → PARCIAL_ACREDITADO.
     */
    private function actualizarEstadoCobro(Echeq $echeq): void
    {
        if (! $echeq->cobro_id) {
            return;
        }

        // El medio específico de este eCheq pasa a ACREDITADO.
        CobroMedio::where('cobro_id', $echeq->cobro_id)
            ->where('echeq_id', $echeq->id)
            ->update(['estado_acreditacion' => CobroMedio::ESTADO_ACREDITADO]);

        $cobro = Cobro::find($echeq->cobro_id);
        if (! $cobro || $cobro->estado === Cobro::ESTADO_ANULADO) {
            return;
        }

        $estados = CobroMedio::where('cobro_id', $cobro->id)->pluck('estado_acreditacion');

        if ($estados->every(fn ($e) => $e === CobroMedio::ESTADO_ACREDITADO)) {
            $cobro->update(['estado' => Cobro::ESTADO_ACREDITADO]);
        } elseif ($estados->contains(CobroMedio::ESTADO_ACREDITADO)) {
            $cobro->update(['estado' => Cobro::ESTADO_PARCIAL_ACREDITADO]);
        }
    }

    /**
     * Marca el medio del eCheq como RECHAZADO y reescala estado del cobro.
     * RN-19 establece: si era único medio → cobro RECHAZADO; si había otros
     * medios que siguen vigentes → RECHAZADO_PARCIAL.
     */
    private function rechazarMedioCobro(Echeq $echeq): void
    {
        if (! $echeq->cobro_id) {
            return;
        }

        CobroMedio::where('cobro_id', $echeq->cobro_id)
            ->where('echeq_id', $echeq->id)
            ->update(['estado_acreditacion' => CobroMedio::ESTADO_RECHAZADO]);

        $cobro = Cobro::find($echeq->cobro_id);
        if (! $cobro) {
            return;
        }

        $otros = CobroMedio::where('cobro_id', $cobro->id)
            ->where('echeq_id', '!=', $echeq->id)
            ->get();

        if ($otros->isEmpty()) {
            $cobro->update(['estado' => Cobro::ESTADO_RECHAZADO]);
        } else {
            $cobro->update(['estado' => Cobro::ESTADO_RECHAZADO_PARCIAL]);
        }
    }
}
