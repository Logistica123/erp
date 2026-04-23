<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\OpItem;
use App\Erp\Models\Tesoreria\OpMedio;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Support\AuditLogger;
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
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea una OP en estado BORRADOR. Si se envían items y/o medios, los
     * persiste y valida que la suma de medios iguale el importe neto.
     */
    public function crear(array $data): OrdenPago
    {
        // RN-31 gate: items tipo FACTURA_COMPRA deben apuntar a facturas CONTROLADAS.
        foreach ($data['items'] ?? [] as $idx => $i) {
            if (! empty($i['comprobante_id']) && ($i['tipo_item'] ?? 'FACTURA_COMPRA') === OpItem::TIPO_FACTURA_COMPRA) {
                $estado = DB::table('erp_facturas_compra')
                    ->where('id', $i['comprobante_id'])
                    ->value('estado');
                if (! $estado) {
                    throw new DomainException('FACTURA_NO_ENCONTRADA: item #'.($idx + 1).' comprobante_id='.$i['comprobante_id']);
                }
                if (! in_array($estado, ['CONTROLADA', 'PAGO_PARCIAL', 'PAGADA'], true)) {
                    throw new DomainException(sprintf(
                        'RN-31: factura compra #%d está %s — solo CONTROLADA admite pago',
                        $i['comprobante_id'],
                        $estado
                    ));
                }
            }
        }

        return DB::transaction(function () use ($data) {
            $auxiliar = Auxiliar::findOrFail($data['auxiliar_id']);

            $numero = $this->proximoNumero($data['empresa_id']);

            $op = OrdenPago::create([
                'empresa_id' => $data['empresa_id'],
                'numero' => $numero,
                'fecha' => $data['fecha'],
                'tipo' => $data['tipo'] ?? 'PROVEEDOR',
                'auxiliar_id' => $auxiliar->id,
                'liq_encabezado_id' => $data['liq_encabezado_id'] ?? null,
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

            $this->sincronizarItems($op, $data['items'] ?? []);
            $this->sincronizarMedios($op, $data['medios'] ?? []);

            return $op->fresh(['items', 'medios']);
        });
    }

    /**
     * Actualiza una OP en estado BORRADOR. Cualquier otro estado rechaza
     * con OP_INMUTABLE (trigger SQL trg_op_inmutable_bu respalda).
     */
    public function actualizar(OrdenPago $op, array $data): OrdenPago
    {
        if ($op->estado !== OrdenPago::ESTADO_BORRADOR) {
            throw new DomainException('OP_INMUTABLE: solo se edita en BORRADOR (actual: '.$op->estado.')');
        }

        return DB::transaction(function () use ($op, $data) {
            $op->update(array_intersect_key($data, array_flip([
                'fecha', 'tipo', 'moneda_id', 'cotizacion',
                'importe', 'importe_bruto', 'total_retenciones',
                'concepto', 'observaciones', 'liq_encabezado_id',
            ])));

            if (array_key_exists('items', $data)) {
                $op->items()->delete();
                $this->sincronizarItems($op, $data['items'] ?? []);
            }
            if (array_key_exists('medios', $data)) {
                $op->medios()->delete();
                $this->sincronizarMedios($op, $data['medios'] ?? []);
            }

            return $op->fresh(['items', 'medios']);
        });
    }

    /**
     * BORRADOR → CARGADA_BANCO. Tesorero marca que ya cargó la OP en el
     * home banking. A partir de acá el trigger trg_op_inmutable_bu (RN-17)
     * rechaza cualquier cambio en campos críticos.
     */
    public function cargarBanco(OrdenPago $op, User $usuario): OrdenPago
    {
        if ($op->estado !== OrdenPago::ESTADO_BORRADOR) {
            throw new DomainException('OP_ESTADO_INVALIDO: solo se carga desde BORRADOR (actual: '.$op->estado.')');
        }

        // Validación final pre-carga: medios deben balancear con importe.
        $this->validarBalanceMedios($op);

        return DB::transaction(function () use ($op, $usuario) {
            $op->update([
                'estado' => OrdenPago::ESTADO_CARGADA_BANCO,
                'fecha_carga_banco' => now(),
                'cargado_por_user_id' => $usuario->id,
            ]);

            $this->audit->logEvento(
                accion: 'OP_CARGADA_BANCO',
                modulo: 'tesoreria',
                descripcion: sprintf('OP %s marcada como cargada en home banking por %s', $op->numero, $usuario->name),
                empresaId: $op->empresa_id,
            );

            return $op->fresh();
        });
    }

    /**
     * CARGADA_BANCO → LIBERADA. Marca que Dirección liberó la operación en
     * el home banking (el ERP solo registra el hecho informado por el usuario).
     */
    public function liberar(OrdenPago $op, User $usuario): OrdenPago
    {
        if ($op->estado !== OrdenPago::ESTADO_CARGADA_BANCO) {
            throw new DomainException('OP_ESTADO_INVALIDO: solo se libera desde CARGADA_BANCO (actual: '.$op->estado.')');
        }

        return DB::transaction(function () use ($op, $usuario) {
            $op->update([
                'estado' => OrdenPago::ESTADO_LIBERADA,
                'fecha_liberacion' => now(),
                'liberado_por_user_id' => $usuario->id,
            ]);

            $this->audit->logEvento(
                accion: 'OP_LIBERADA',
                modulo: 'tesoreria',
                descripcion: sprintf('OP %s liberada por %s', $op->numero, $usuario->name),
                empresaId: $op->empresa_id,
            );

            return $op->fresh();
        });
    }

    /**
     * CARGADA_BANCO | LIBERADA → RECHAZADA. El banco rebotó la operación
     * (fondos, CBU erróneo, etc.). La OP puede re-crearse desde cero.
     */
    public function rechazar(OrdenPago $op, string $motivo, User $usuario): OrdenPago
    {
        if (! in_array($op->estado, [OrdenPago::ESTADO_CARGADA_BANCO, OrdenPago::ESTADO_LIBERADA], true)) {
            throw new DomainException('OP_ESTADO_INVALIDO: solo se rechaza desde CARGADA_BANCO o LIBERADA (actual: '.$op->estado.')');
        }

        return DB::transaction(function () use ($op, $motivo, $usuario) {
            $op->update([
                'estado' => OrdenPago::ESTADO_RECHAZADA,
                'motivo_rechazo' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'OP_RECHAZADA',
                modulo: 'tesoreria',
                descripcion: sprintf('OP %s rechazada: %s', $op->numero, $motivo),
                empresaId: $op->empresa_id,
            );

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
        // Flujo ideal: LIBERADA → PAGADA. Permitimos también desde BORRADOR
        // para operaciones expeditivas (pago de contado sin pasar por cargar
        // al banco). CARGADA_BANCO sin liberar no paga (el banco aún no
        // confirmó que el débito salió).
        if ($op->estado === OrdenPago::ESTADO_CARGADA_BANCO) {
            throw new DomainException('OP_FALTA_LIBERAR: la OP está CARGADA_BANCO pero aún no LIBERADA');
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

    /**
     * Crea registros erp_op_items para esta OP. Cada item: tipo_item + concepto
     * + importe, opcionalmente comprobante_id (si FACTURA_COMPRA) o
     * cuenta_contable_id (si OTRO/ADELANTO).
     */
    private function sincronizarItems(OrdenPago $op, array $items): void
    {
        foreach ($items as $idx => $i) {
            OpItem::create([
                'op_id' => $op->id,
                'orden' => $i['orden'] ?? ($idx + 1),
                'tipo_item' => $i['tipo_item'] ?? OpItem::TIPO_OTRO,
                'comprobante_id' => $i['comprobante_id'] ?? null,
                'cuenta_contable_id' => $i['cuenta_contable_id'] ?? null,
                'concepto' => $i['concepto'],
                'importe' => $i['importe'],
            ]);
        }
    }

    /**
     * Crea registros erp_op_medios para esta OP. Cada medio: medio_pago_id +
     * importe, opcionalmente cuenta_bancaria_id y referencia (nro de transf).
     */
    private function sincronizarMedios(OrdenPago $op, array $medios): void
    {
        foreach ($medios as $m) {
            OpMedio::create([
                'op_id' => $op->id,
                'medio_pago_id' => $m['medio_pago_id'],
                'cuenta_bancaria_id' => $m['cuenta_bancaria_id'] ?? null,
                'importe' => $m['importe'],
                'referencia' => $m['referencia'] ?? null,
            ]);
        }
    }

    /**
     * Valida que la suma de medios == importe neto de la OP.
     * Si no hay medios aún, no valida (la OP puede estar en BORRADOR sin
     * medios aún y validar al pasar a CARGADA_BANCO).
     */
    private function validarBalanceMedios(OrdenPago $op): void
    {
        $sumaMedios = (float) $op->medios()->sum('importe');
        if ($sumaMedios <= 0) {
            return; // OP sin medios explícitos — el pago se resuelve al pagar().
        }

        $diferencia = round($sumaMedios - (float) $op->importe, 2);
        if (abs($diferencia) > 0.01) {
            throw new DomainException(sprintf(
                'OP_MEDIOS_DESBALANCEADOS: suma medios %.2f ≠ importe %.2f (dif %.2f)',
                $sumaMedios,
                $op->importe,
                $diferencia
            ));
        }
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
