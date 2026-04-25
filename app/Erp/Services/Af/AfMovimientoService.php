<?php

namespace App\Erp\Services\Af;

use App\Erp\Models\Af\AfAmortizacion;
use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfMovimiento;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos contables sobre un bien (SPEC 06 RN-78/80/81).
 *
 *  - mejora(): RN-78. Suma `importe` al valor origen, recalcula la base
 *    de amortización desde el mes siguiente. La amort. acumulada previa
 *    no se toca. Devuelve datos para asiento (DEBE BdU / HABER Proveedor
 *    o Caja según caller).
 *  - revaluo(): RN-80. Override del valor origen. Diferencia va a Reserva
 *    por revalúo técnico (PN). Genera movimiento REVALUO.
 *  - baja(): RN-81. Calcula valor_residual_contable y resultado_baja,
 *    cambia estado=BAJA y devuelve la propuesta de asiento (DEBE
 *    Amort.Acum + Caja/DxV / HABER BdU + Resultado por baja según signo).
 *
 * El `asiento_id` queda `null` hasta que el caller cree el asiento real
 * con `AsientoService` y llame a `vincularAsiento()` del movimiento.
 */
class AfMovimientoService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * RN-78 — Mejora del bien (extiende vida útil o capacidad).
     *
     * @param array{importe:float, fecha?:string, descripcion?:string,
     *              factura_compra_id?:int, vu_extension_meses?:int} $datos
     *
     * @return array{movimiento: AfMovimiento, propuesta_asiento: array}
     */
    public function mejora(AfBien $bien, array $datos, User $usuario): array
    {
        if ($bien->estado === 'BAJA') {
            throw new DomainException('AF_BIEN_DADO_DE_BAJA');
        }
        $importe = (float) ($datos['importe'] ?? 0);
        if ($importe <= 0) {
            throw new DomainException('AF_MEJORA_IMPORTE_INVALIDO');
        }

        return DB::transaction(function () use ($bien, $datos, $usuario, $importe) {
            $valorOrigenAnterior = (float) $bien->valor_origen;
            $valorOrigenNuevo = round($valorOrigenAnterior + $importe, 2);

            // Si la mejora extiende VU, sumamos meses al override del bien.
            $extension = (int) ($datos['vu_extension_meses'] ?? 0);
            if ($extension > 0) {
                $bien->vida_util_contable_meses = ($bien->vida_util_contable_meses ?? $bien->categoria->vida_util_contable_meses) + $extension;
                $bien->vida_util_fiscal_meses   = ($bien->vida_util_fiscal_meses   ?? $bien->categoria->vida_util_fiscal_meses)   + $extension;
            }
            $bien->valor_origen = $valorOrigenNuevo;
            $bien->save();

            $mov = AfMovimiento::create([
                'bien_id'           => $bien->id,
                'tipo'              => 'MEJORA',
                'fecha'             => $datos['fecha'] ?? now()->toDateString(),
                'importe'           => $importe,
                'descripcion'       => $datos['descripcion'] ?? "Mejora bien (+{$importe})",
                'factura_compra_id' => $datos['factura_compra_id'] ?? null,
                'usuario_id'        => $usuario->id,
            ]);

            $this->audit->log('af_mejora', $bien, ['valor_origen' => $valorOrigenAnterior],
                ['valor_origen' => $valorOrigenNuevo, 'importe' => $importe],
                "Mejora bien #{$bien->id} (+{$importe}) por user #{$usuario->id}");

            return [
                'movimiento' => $mov,
                'propuesta_asiento' => [
                    'fecha'    => $mov->fecha,
                    'glosa'    => "Mejora AF: {$bien->nro_inventario} {$bien->descripcion}",
                    'origen'   => 'AJUSTE',
                    'lineas'   => [
                        ['cuenta_id' => $bien->categoria->cuenta_bien_id, 'debe' => $importe, 'haber' => 0,
                         'glosa' => 'Activación mejora'],
                        // El haber lo completa el caller (caja, proveedor, banco, etc.)
                    ],
                ],
            ];
        });
    }

    /**
     * RN-80 — Revalúo técnico. Cambia valor origen contra Reserva por revalúo.
     *
     * @param array{nuevo_valor:float, fecha?:string, descripcion?:string,
     *              cuenta_reserva_revaluo_id?:int} $datos
     */
    public function revaluo(AfBien $bien, array $datos, User $usuario): array
    {
        if ($bien->estado === 'BAJA') {
            throw new DomainException('AF_BIEN_DADO_DE_BAJA');
        }
        $nuevoValor = (float) ($datos['nuevo_valor'] ?? 0);
        if ($nuevoValor <= 0) {
            throw new DomainException('AF_REVALUO_VALOR_INVALIDO');
        }
        $anterior = (float) $bien->valor_origen;
        $diferencia = round($nuevoValor - $anterior, 2);
        if (abs($diferencia) < 0.01) {
            throw new DomainException('AF_REVALUO_SIN_DIFERENCIA');
        }

        return DB::transaction(function () use ($bien, $datos, $usuario, $anterior, $nuevoValor, $diferencia) {
            $bien->update(['valor_origen' => $nuevoValor]);

            $mov = AfMovimiento::create([
                'bien_id'      => $bien->id,
                'tipo'         => 'REVALUO',
                'fecha'        => $datos['fecha'] ?? now()->toDateString(),
                'importe'      => $diferencia,
                'descripcion'  => $datos['descripcion'] ?? sprintf('Revalúo técnico (%+0.2f)', $diferencia),
                'usuario_id'   => $usuario->id,
            ]);

            $this->audit->log('af_revaluo', $bien, ['valor_origen' => $anterior],
                ['valor_origen' => $nuevoValor, 'diferencia' => $diferencia],
                "Revalúo bien #{$bien->id} ({$anterior} → {$nuevoValor}) por user #{$usuario->id}");

            $signoDebe = $diferencia > 0;
            return [
                'movimiento' => $mov,
                'propuesta_asiento' => [
                    'fecha'  => $mov->fecha,
                    'glosa'  => "Revalúo AF: {$bien->nro_inventario}",
                    'origen' => 'AJUSTE',
                    'lineas' => [
                        ['cuenta_id' => $bien->categoria->cuenta_bien_id,
                         'debe' => $signoDebe ? abs($diferencia) : 0,
                         'haber' => $signoDebe ? 0 : abs($diferencia),
                         'glosa' => 'Revalúo técnico'],
                        // Caller completa con cuenta de Reserva por revalúo (PN).
                    ],
                ],
            ];
        });
    }

    /**
     * RN-81 — Baja del bien con cálculo de resultado.
     *
     * @param array{fecha?:string, motivo:string, valor_recupero?:float,
     *              factura_venta_baja_id?:int, cuenta_recupero_id?:int} $datos
     */
    public function baja(AfBien $bien, array $datos, User $usuario): array
    {
        if ($bien->estado === 'BAJA') {
            throw new DomainException('AF_BIEN_YA_DADO_DE_BAJA');
        }
        $valorRecupero = (float) ($datos['valor_recupero'] ?? 0);
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if ($motivo === '') {
            throw new DomainException('AF_BAJA_REQUIERE_MOTIVO');
        }

        return DB::transaction(function () use ($bien, $datos, $usuario, $valorRecupero, $motivo) {
            $amortAcum = (float) AfAmortizacion::where('bien_id', $bien->id)
                ->orderByDesc('periodo_anio')->orderByDesc('periodo_mes')
                ->value('amort_contable_acum') ?? 0;

            $valorResidual = round((float) $bien->valor_origen - $amortAcum, 2);
            $resultado = round($valorRecupero - $valorResidual, 2);

            $bien->update([
                'estado'                => 'BAJA',
                'fecha_baja'            => $datos['fecha'] ?? now()->toDateString(),
                'motivo_baja'           => $motivo,
                'valor_recupero'        => $valorRecupero,
                'factura_venta_baja_id' => $datos['factura_venta_baja_id'] ?? null,
            ]);

            $mov = AfMovimiento::create([
                'bien_id'      => $bien->id,
                'tipo'         => 'BAJA',
                'fecha'        => $bien->fecha_baja,
                'importe'      => $valorRecupero,
                'descripcion'  => "Baja: {$motivo}. Residual={$valorResidual}, resultado={$resultado}",
                'usuario_id'   => $usuario->id,
            ]);

            $this->audit->log('af_baja', $bien,
                ['valor_origen' => $bien->valor_origen, 'amort_acum' => $amortAcum],
                ['valor_recupero' => $valorRecupero, 'resultado' => $resultado, 'motivo' => $motivo],
                "Baja bien #{$bien->id} '{$bien->nro_inventario}' (resultado={$resultado}) por user #{$usuario->id}");

            $cuentaRes = $resultado >= 0
                ? $bien->categoria->cuenta_resultado_baja_pos_id
                : $bien->categoria->cuenta_resultado_baja_neg_id;

            return [
                'movimiento'           => $mov,
                'amort_acumulada'      => $amortAcum,
                'valor_residual'       => $valorResidual,
                'resultado_baja'       => $resultado,
                'propuesta_asiento'    => [
                    'fecha'  => $mov->fecha,
                    'glosa'  => "Baja AF: {$bien->nro_inventario} ({$motivo})",
                    'origen' => 'AJUSTE',
                    'lineas' => [
                        // Cierra la amortización acumulada.
                        ['cuenta_id' => $bien->categoria->cuenta_amort_acum_id,
                         'debe' => $amortAcum, 'haber' => 0, 'glosa' => 'Cierre amort acumulada'],
                        // Caja / DxV por el recupero (caller resuelve qué cuenta).
                        ['cuenta_id' => $datos['cuenta_recupero_id'] ?? null,
                         'debe' => $valorRecupero, 'haber' => 0, 'glosa' => 'Recupero baja'],
                        // Da de baja el bien por su valor origen.
                        ['cuenta_id' => $bien->categoria->cuenta_bien_id,
                         'debe' => 0, 'haber' => (float) $bien->valor_origen, 'glosa' => 'Baja AF'],
                        // Resultado (positivo o negativo).
                        ['cuenta_id' => $cuentaRes,
                         'debe'  => $resultado < 0 ? abs($resultado) : 0,
                         'haber' => $resultado > 0 ? abs($resultado) : 0,
                         'glosa' => 'Resultado por baja'],
                    ],
                ],
            ];
        });
    }

    /**
     * Vincula un asiento ya creado por el caller a un movimiento AF.
     */
    public function vincularAsiento(AfMovimiento $movimiento, int $asientoId): AfMovimiento
    {
        $movimiento->update(['asiento_id' => $asientoId]);
        return $movimiento->fresh();
    }
}
