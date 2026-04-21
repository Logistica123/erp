<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de la OP (SPEC_02 §OP, RN-17):
 *   BORRADOR → CARGADA_BANCO → LIBERADA → PAGADA
 *                          ↘ RECHAZADA
 *   BORRADOR → ANULADA
 *
 * MVP aquí: crear BORRADOR + transicionar directamente a PAGADA (cuando
 * marcamos como pagada se genera asiento contable y un movimiento bancario
 * conciliado automático en la cuenta bancaria indicada).
 *
 * Futuras iteraciones: flujo multi-nivel con aprobaciones, medios de pago
 * múltiples (1 OP puede tener varios medios), reconciliación ECHEQ.
 */
class OrdenPagoService
{
    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly MovimientoBancarioService $movService,
    ) {}

    public function crear(array $data): OrdenPago
    {
        return DB::transaction(function () use ($data) {
            $auxiliar = Auxiliar::findOrFail($data['auxiliar_id']);

            $numero = $this->proximoNumero($data['empresa_id']);

            $op = OrdenPago::create([
                'empresa_id' => $data['empresa_id'],
                'numero' => $numero,
                'fecha' => $data['fecha'],
                'tipo' => $data['tipo'] ?? 'PROVEEDOR',
                'auxiliar_id' => $auxiliar->id,
                'moneda_id' => $data['moneda_id'],
                'cotizacion' => $data['cotizacion'] ?? 1.0,
                'importe' => $data['importe'],
                'importe_bruto' => $data['importe_bruto'] ?? $data['importe'],
                'total_retenciones' => $data['total_retenciones'] ?? 0,
                'estado' => OrdenPago::ESTADO_BORRADOR,
                'concepto' => $data['concepto'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'creado_por_user_id' => $data['usuario_id'],
            ]);

            return $op->fresh();
        });
    }

    /**
     * Marca la OP como PAGADA, generando:
     *   - asiento contable (diario TES): DEBE cuenta del auxiliar (2.1.1.01), HABER cuenta bancaria
     *   - movimiento bancario CONCILIADO en la cuenta bancaria elegida
     */
    public function pagar(OrdenPago $op, int $cuentaBancariaId, User $usuario, ?string $concepto = null): array
    {
        if ($op->estado === OrdenPago::ESTADO_PAGADA) {
            throw new DomainException('OP_YA_PAGADA');
        }
        if (in_array($op->estado, [OrdenPago::ESTADO_ANULADA, OrdenPago::ESTADO_RECHAZADA], true)) {
            throw new DomainException('OP_NO_PAGABLE: estado '.$op->estado);
        }

        return DB::transaction(function () use ($op, $cuentaBancariaId, $usuario, $concepto) {
            $cuentaBancaria = CuentaBancaria::where('empresa_id', $op->empresa_id)
                ->where('id', $cuentaBancariaId)
                ->firstOrFail();

            $auxiliar = $op->auxiliar;
            $cuentaContableProveedor = $this->resolverCuentaContableProveedor($auxiliar);

            $importe = (float) $op->importe;
            $glosa = $concepto ?? sprintf(
                'Pago OP %s · %s',
                $op->numero,
                $auxiliar->nombre,
            );

            // Crear el movimiento bancario PENDIENTE
            $mov = $this->movService->crearManual([
                'cuenta_bancaria_id' => $cuentaBancaria->id,
                'fecha' => $op->fecha->toDateString(),
                'concepto' => $glosa,
                'debito' => $importe,  // egreso de la cta bancaria
                'credito' => 0,
                'usuario_id' => $usuario->id,
            ]);

            // Conciliarlo inmediatamente — genera el asiento automático
            $mov = $this->movService->conciliar(
                mov: $mov,
                cuentaContableContraparteId: $cuentaContableProveedor->id,
                usuarioId: $usuario->id,
                auxiliarId: $cuentaContableProveedor->admite_auxiliar ? $auxiliar->id : null,
                glosa: $glosa,
            );

            $op->update([
                'estado' => OrdenPago::ESTADO_PAGADA,
                'fecha_pago' => now(),
                'asiento_id' => $mov->asiento_id,
            ]);

            return ['op' => $op->fresh(), 'movimiento' => $mov, 'asiento_id' => $mov->asiento_id];
        });
    }

    public function anular(OrdenPago $op, string $motivo): OrdenPago
    {
        if ($op->estado === OrdenPago::ESTADO_PAGADA) {
            throw new DomainException('OP_PAGADA: no se puede anular — anulá el asiento y mov bancario primero.');
        }
        if ($op->estado === OrdenPago::ESTADO_ANULADA) {
            throw new DomainException('OP_YA_ANULADA');
        }

        $op->update([
            'estado' => OrdenPago::ESTADO_ANULADA,
            'motivo_anulacion' => $motivo,
        ]);

        return $op->fresh();
    }

    private function proximoNumero(int $empresaId): string
    {
        $ultimo = DB::table('erp_ordenes_pago')
            ->where('empresa_id', $empresaId)
            ->orderByDesc('id')
            ->value('numero');

        $n = $ultimo ? (int) str_replace(['OP-', '-'], '', $ultimo) + 1 : 1;

        return 'OP-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Busca la cuenta contable apropiada para el auxiliar (proveedor/distribuidor).
     * Convención: auxiliares tipo PROVEEDOR → cuenta 2.1.1.01, DISTRIBUIDOR → 2.1.1.03.
     */
    private function resolverCuentaContableProveedor(Auxiliar $auxiliar): \App\Erp\Models\CuentaContable
    {
        $codigoCta = match (strtoupper($auxiliar->tipo ?? '')) {
            'DISTRIBUIDOR', 'PERSONA', 'EMPLEADO' => '2.1.1.03',
            default => '2.1.1.01',
        };

        $cuenta = \App\Erp\Models\CuentaContable::where('empresa_id', $auxiliar->empresa_id)
            ->where('codigo', $codigoCta)
            ->first();

        if (! $cuenta) {
            throw new DomainException("CUENTA_CONTABLE_NO_ENCONTRADA: {$codigoCta}");
        }

        return $cuenta;
    }
}
