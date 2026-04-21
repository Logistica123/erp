<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de un movimiento bancario:
 *   PENDIENTE → (etiquetado auto o manual) → ETIQUETADO
 *   ETIQUETADO → (conciliar) → CONCILIADO + genera asiento
 *   PENDIENTE → (ignorar con motivo) → IGNORADO (no aporta a saldos)
 *
 * Cada movimiento conciliado dispara el trigger SQL `trg_mov_bancario_saldo_au`
 * que actualiza `erp_cuentas_bancarias.saldo_actual`.
 *
 * El asiento que se genera es en el diario BAN (Bancos):
 *   - Si es crédito en cuenta bancaria (cobro, ingreso):
 *       DEBE cuenta_bancaria (cta contable asociada)
 *       HABER cuenta_contable imputada (ej. 4.1.01 ventas)
 *   - Si es débito (pago, egreso):
 *       DEBE cuenta_contable imputada (ej. 2.1.1.01 proveedores)
 *       HABER cuenta_bancaria
 */
class MovimientoBancarioService
{
    public function __construct(private readonly AsientoService $asientoService) {}

    /**
     * Carga manual de un movimiento bancario (sin pasar por extracto).
     * Crea un "extracto virtual" por día si no existe, agrupando los manuales.
     */
    public function crearManual(array $data): MovimientoBancario
    {
        $cuentaBancaria = CuentaBancaria::findOrFail($data['cuenta_bancaria_id']);

        return DB::transaction(function () use ($data, $cuentaBancaria) {
            $extracto = $this->obtenerExtractoVirtualDelDia(
                $cuentaBancaria->id,
                $data['fecha'],
                $data['usuario_id'],
            );

            $hash = hash('sha256', implode('|', [
                $cuentaBancaria->id,
                $data['fecha'],
                $data['concepto'],
                $data['debito'] ?? 0,
                $data['credito'] ?? 0,
                uniqid('', true),
            ]));

            $mov = MovimientoBancario::create([
                'extracto_id' => $extracto->id,
                'cuenta_bancaria_id' => $cuentaBancaria->id,
                'fecha' => $data['fecha'],
                'fecha_valor' => $data['fecha_valor'] ?? $data['fecha'],
                'concepto' => $data['concepto'],
                'comprobante_banco' => $data['comprobante_banco'] ?? null,
                'debito' => $data['debito'] ?? 0,
                'credito' => $data['credito'] ?? 0,
                'estado' => MovimientoBancario::ESTADO_PENDIENTE,
                'hash_linea' => $hash,
            ]);

            // Actualizar cantidad de movimientos + saldo final del extracto
            $extracto->increment('cant_movimientos');

            return $mov;
        });
    }

    /**
     * Marca el movimiento como CONCILIADO y genera el asiento contable.
     */
    public function conciliar(
        MovimientoBancario $mov,
        int $cuentaContableContraparteId,
        int $usuarioId,
        ?int $centroCostoId = null,
        ?int $auxiliarId = null,
        ?string $glosa = null,
    ): MovimientoBancario {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_YA_CONCILIADO');
        }
        if ($mov->estado === MovimientoBancario::ESTADO_IGNORADO) {
            throw new DomainException('MOVIMIENTO_IGNORADO: reabrilo antes de conciliar.');
        }

        return DB::transaction(function () use ($mov, $cuentaContableContraparteId, $usuarioId, $centroCostoId, $auxiliarId, $glosa) {
            $cuentaBancaria = $mov->cuentaBancaria;
            $cuentaCtaBancariaId = $cuentaBancaria->cuenta_contable_id;
            $contraparte = CuentaContable::findOrFail($cuentaContableContraparteId);

            $debe = (float) $mov->debito;   // ingreso → cta bancaria debe
            $haber = (float) $mov->credito; // egreso → cta bancaria haber

            $monto = max($debe, $haber);

            $diarioBan = DB::table('erp_diarios')
                ->where('empresa_id', $cuentaBancaria->empresa_id)
                ->where('codigo', 'BAN')
                ->value('id');

            // Armar movimientos según naturaleza
            $movs = $mov->credito > 0
                // Crédito bancario (ingreso a la cuenta): DEBE cta bancaria, HABER contraparte
                ? [
                    ['cuenta_id' => $cuentaCtaBancariaId, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa ?? $mov->concepto],
                    ['cuenta_id' => $contraparte->id, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa ?? $mov->concepto, 'centro_costo_id' => $centroCostoId, 'auxiliar_id' => $auxiliarId],
                ]
                // Débito bancario (egreso): DEBE contraparte, HABER cta bancaria
                : [
                    ['cuenta_id' => $contraparte->id, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa ?? $mov->concepto, 'centro_costo_id' => $centroCostoId, 'auxiliar_id' => $auxiliarId],
                    ['cuenta_id' => $cuentaCtaBancariaId, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa ?? $mov->concepto],
                ];

            // Fallback CC CENTRAL para cualquier línea cuya cuenta exija CC y no lo tenga.
            $ccFallbackId = DB::table('erp_centros_costo')
                ->where('empresa_id', $cuentaBancaria->empresa_id)
                ->where('codigo', 'CENTRAL')
                ->value('id');

            foreach ($movs as &$m) {
                if (empty($m['centro_costo_id'])) {
                    $cc = CuentaContable::find($m['cuenta_id']);
                    if ($cc && $cc->admite_cc) {
                        $m['centro_costo_id'] = $ccFallbackId;
                    }
                }
            }
            unset($m);

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $cuentaBancaria->empresa_id,
                'diario_id' => $diarioBan,
                'fecha' => $mov->fecha->toDateString(),
                'glosa' => $glosa ?? $mov->concepto,
                'origen' => 'BANCO',
                'origen_id' => $mov->id,
                'origen_tabla' => 'erp_movimientos_bancarios',
                'usuario_id' => $usuarioId,
                'movimientos' => $movs,
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            $mov->update([
                'estado' => MovimientoBancario::ESTADO_CONCILIADO,
                'cuenta_contable_propuesta_id' => $contraparte->id,
                'asiento_id' => $asiento->id,
            ]);

            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    public function ignorar(MovimientoBancario $mov, int $motivoIgnoradoId, ?string $observacion = null): MovimientoBancario
    {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_CONCILIADO: no se puede ignorar uno ya conciliado (anulá el asiento primero).');
        }

        $mov->update([
            'estado' => MovimientoBancario::ESTADO_IGNORADO,
            'motivo_ignorado_id' => $motivoIgnoradoId,
            'observacion' => $observacion,
        ]);

        return $mov->fresh();
    }

    /**
     * Crea o encuentra el "extracto virtual" del día para carga manual
     * (pseudo-extracto que agrupa los movs cargados a mano por día).
     */
    private function obtenerExtractoVirtualDelDia(int $cuentaBancariaId, string $fecha, int $usuarioId): ExtractoBancario
    {
        $hashVirtual = hash('sha256', "manual|{$cuentaBancariaId}|{$fecha}");

        return ExtractoBancario::firstOrCreate(
            ['hash_archivo' => $hashVirtual],
            [
                'cuenta_bancaria_id' => $cuentaBancariaId,
                'fecha_desde' => $fecha,
                'fecha_hasta' => $fecha,
                'nombre_archivo' => "Manual · {$fecha}",
                'ruta_archivo' => null,
                'cant_movimientos' => 0,
                'importado_por_user_id' => $usuarioId,
                'importado_at' => now(),
                'observaciones' => 'Extracto virtual para cargas manuales del día.',
            ]
        );
    }
}
