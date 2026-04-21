<?php

namespace App\Erp\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra un cobro simple contra una factura de venta.
 *
 * MVP: 1 factura = 1 cobro = 1 medio de pago (sin retenciones), total.
 * Genera: erp_cobros + erp_cobro_items + erp_cobro_medios + asiento contable
 * (Debe Caja/Banco, Haber Deudores) y marca la factura como COBRADA.
 */
class CobroFacturaService
{
    public function __construct(private AsientoService $asientoService) {}

    /**
     * @param array{
     *   factura_id:int,
     *   fecha:string,
     *   medio_pago_id:int,
     *   caja_id?:?int,
     *   cuenta_bancaria_id?:?int,
     *   referencia?:?string,
     * }  $input
     */
    public function cobrar(array $input, int $empresaId = 1, int $usuarioId = 1): array
    {
        return DB::transaction(function () use ($input, $empresaId, $usuarioId) {
            $factura = DB::table('erp_facturas_venta as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->where('f.empresa_id', $empresaId)
                ->where('f.id', $input['factura_id'])
                ->whereNull('f.deleted_at')
                ->select('f.*', 'tc.clase as cbte_clase', 'tc.signo as cbte_signo')
                ->lockForUpdate()
                ->first();
            if (!$factura) throw new RuntimeException('Factura no existe');
            if ($factura->estado !== 'EMITIDA') throw new RuntimeException("Factura no está EMITIDA (estado: {$factura->estado})");
            if ($factura->cbte_clase !== 'FACTURA') throw new RuntimeException('Solo se cobran facturas, no NC/ND');

            $medio = DB::table('erp_medios_pago')->where('id', $input['medio_pago_id'])->first();
            if (!$medio) throw new RuntimeException('Medio de pago no existe');

            // Validar cuenta destino según el medio
            $cuentaCajaId = null;
            $cuentaBancariaId = null;
            $cuentaContableDebitoId = null;
            if ($medio->afecta_caja) {
                $caja = DB::table('erp_cajas')
                    ->where('empresa_id', $empresaId)
                    ->where('id', $input['caja_id'] ?? 0)
                    ->first();
                if (!$caja) throw new RuntimeException('Caja requerida para este medio de pago');
                $cuentaCajaId = $caja->id;
                $cuentaContableDebitoId = $caja->cuenta_contable_id;
            } elseif ($medio->afecta_banco) {
                $banco = DB::table('erp_cuentas_bancarias')
                    ->where('empresa_id', $empresaId)
                    ->where('id', $input['cuenta_bancaria_id'] ?? 0)
                    ->first();
                if (!$banco) throw new RuntimeException('Cuenta bancaria requerida para este medio de pago');
                $cuentaBancariaId = $banco->id;
                $cuentaContableDebitoId = $banco->cuenta_contable_id;
            } else {
                throw new RuntimeException('Medio de pago mal configurado (no afecta caja ni banco)');
            }

            $importe = (float) $factura->imp_total;
            $fechaCobro = $input['fecha'];
            $numeroCobro = 'COB-'.str_pad((string) $factura->id, 6, '0', STR_PAD_LEFT).'-'.date('YmdHis');

            // 1. Cabecera cobro
            $cobroId = DB::table('erp_cobros')->insertGetId([
                'empresa_id' => $empresaId,
                'numero' => $numeroCobro,
                'fecha' => $fechaCobro,
                'auxiliar_id' => $factura->auxiliar_id,
                'moneda_id' => $factura->moneda_id,
                'cotizacion' => $factura->cotizacion,
                'importe_total' => $importe,
                'total_retenciones' => 0,
                'estado' => 'ACREDITADO',
                'concepto' => "Cobro factura #{$factura->numero}",
                'creado_por_user_id' => $usuarioId,
            ]);

            // 2. Item (factura cobrada)
            DB::table('erp_cobro_items')->insert([
                'cobro_id' => $cobroId,
                'tipo_item' => 'FACTURA_VENTA',
                'factura_id' => $factura->id,
                'concepto' => "FV #{$factura->numero}",
                'importe' => $importe,
            ]);

            // 3. Medio de pago
            DB::table('erp_cobro_medios')->insert([
                'cobro_id' => $cobroId,
                'medio_pago_id' => $medio->id,
                'caja_id' => $cuentaCajaId,
                'cuenta_bancaria_id' => $cuentaBancariaId,
                'importe' => $importe,
                'referencia' => $input['referencia'] ?? null,
                'estado_acreditacion' => 'ACREDITADO',
            ]);

            // 4. Asiento contable
            $diarioCbr = DB::table('erp_diarios')
                ->where('empresa_id', $empresaId)->where('codigo', 'TES')
                ->value('id') ?? DB::table('erp_diarios')
                    ->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
            if (!$diarioCbr) throw new RuntimeException('Diario TES/GEN no existe');

            $ccGeneral = DB::table('erp_centros_costo')
                ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
            $cuentaDeudoresId = DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)->where('codigo', '1.1.4.01')->value('id');

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioCbr,
                'fecha' => $fechaCobro,
                'glosa' => "Cobro FV #{$factura->numero} — {$medio->nombre}",
                'origen' => 'COBRO',
                'origen_id' => $cobroId,
                'origen_tabla' => 'erp_cobros',
                'usuario_id' => $usuarioId,
                'movimientos' => [
                    [
                        'cuenta_id' => $cuentaContableDebitoId,
                        'centro_costo_id' => $this->admiteCc($cuentaContableDebitoId) ? $ccGeneral : null,
                        'auxiliar_id' => null,
                        'debe' => $importe,
                        'haber' => 0,
                        'glosa' => "Cobro {$medio->nombre}",
                    ],
                    [
                        'cuenta_id' => $cuentaDeudoresId,
                        'centro_costo_id' => $ccGeneral,
                        'auxiliar_id' => $factura->auxiliar_id,
                        'debe' => 0,
                        'haber' => $importe,
                        'glosa' => 'Cancela deudor',
                    ],
                ],
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            DB::table('erp_cobros')->where('id', $cobroId)->update([
                'asiento_id' => $asiento->id,
                'updated_at' => now(),
            ]);

            // 5. Actualizar factura
            DB::table('erp_facturas_venta')->where('id', $factura->id)->update([
                'estado' => 'COBRADA',
                'updated_at' => now(),
            ]);

            return [
                'cobro_id' => $cobroId,
                'numero' => $numeroCobro,
                'asiento_id' => $asiento->id,
                'asiento_numero' => $asiento->numero,
                'importe' => $importe,
                'factura_id' => $factura->id,
                'factura_estado' => 'COBRADA',
            ];
        });
    }

    private function admiteCc(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')->where('id', $cuentaId)->value('admite_cc');
    }
}
