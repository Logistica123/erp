<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\GananciaAnticipo;
use App\Erp\Models\Impuestos\GananciaLiquidacion;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Genera los 10 anticipos de Ganancias para el ejercicio siguiente (RN-57,
 * RG AFIP 5211).
 *
 *   Base_calculo = impuesto_determinado del ejercicio anterior
 *                  − retenciones_sufridas − percepciones_sufridas
 *   Anticipo 1   = 25% de la base
 *   Anticipos 2..10 = 8.33% c/u (sumando 75%, con ajuste en anticipo 10 para cierre exacto)
 *
 * Vencimientos: se leen de `erp_calendario_vencimientos` con
 * `impuesto='GAN_ANTICIPO'` y `periodo_identificador='ANT01..ANT10'` para
 * el año de inicio del ejercicio siguiente. Si no hay calendario, se usa
 * un fallback: mes + (nro * 2) del año siguiente.
 *
 * Idempotente por (ejercicio_id, nro_anticipo). Si se vuelve a correr con
 * base distinta, recalcula todos los anticipos PENDIENTES; los PAGADOS no
 * se tocan.
 */
class GananciasAnticiposService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Genera/regenera los 10 anticipos a partir de una liquidación cerrada.
     *
     * @return array<int, GananciaAnticipo>
     */
    public function generar(GananciaLiquidacion $liq, User $usuario): array
    {
        if ((float) $liq->impuesto_determinado <= 0) {
            throw new DomainException('GAN_ANTICIPOS_SIN_BASE: impuesto_determinado ≤ 0');
        }

        $ejercicioSiguiente = $this->ejercicioSiguiente($liq->ejercicio);

        $base = round(
            (float) $liq->impuesto_determinado
            - (float) $liq->retenciones_sufridas
            - (float) $liq->percepciones_sufridas,
            2
        );

        if ($base <= 0) {
            throw new DomainException('GAN_ANTICIPOS_BASE_NEGATIVA: retenciones+percepciones cubren todo el impuesto');
        }

        return DB::transaction(function () use ($liq, $ejercicioSiguiente, $base, $usuario) {
            $out = [];
            $pcts = $this->porcentajes();     // [25, 8.33, 8.33, ... 8.33, 8.36]  (último balancea)
            $montos = $this->distribuir($base, $pcts);

            for ($i = 1; $i <= 10; $i++) {
                $existente = GananciaAnticipo::where('ejercicio_id', $ejercicioSiguiente->id)
                    ->where('nro_anticipo', $i)
                    ->first();

                if ($existente && $existente->estado !== 'PENDIENTE') {
                    // No tocar anticipos ya pagados / compensados / eximidos.
                    $out[] = $existente;
                    continue;
                }

                $row = GananciaAnticipo::updateOrCreate(
                    ['ejercicio_id' => $ejercicioSiguiente->id, 'nro_anticipo' => $i],
                    [
                        'liquidacion_origen_id' => $liq->id,
                        'fecha_vencimiento'     => $this->vencimiento($ejercicioSiguiente, $i),
                        'base_calculo'          => $base,
                        'porcentaje'            => $pcts[$i - 1],
                        'importe'               => $montos[$i - 1],
                        'estado'                => 'PENDIENTE',
                    ]
                );
                $out[] = $row->fresh();
            }

            $this->audit->log('generar_anticipos_ganancias', $liq, null, [
                'ejercicio_siguiente_id' => $ejercicioSiguiente->id,
                'base' => $base,
                'cantidad' => count($out),
            ], "10 anticipos Ganancias generados sobre base {$base} (user #{$usuario->id})");

            return $out;
        });
    }

    /**
     * Marca un anticipo como pagado y lo vincula a una OP.
     */
    public function pagar(GananciaAnticipo $anticipo, int $ordenPagoId, User $usuario): GananciaAnticipo
    {
        if ($anticipo->estado !== 'PENDIENTE') {
            throw new DomainException("GAN_ANTICIPO_ESTADO_INVALIDO: actual {$anticipo->estado}");
        }

        $anticipo->update([
            'estado'        => 'PAGADO',
            'fecha_pago'    => now()->toDateString(),
            'orden_pago_id' => $ordenPagoId,
        ]);

        $this->audit->log('pagar_anticipo', $anticipo, null, $anticipo->toArray(),
            "Anticipo #{$anticipo->nro_anticipo} pagado via OP #{$ordenPagoId} (user #{$usuario->id})");

        return $anticipo->fresh();
    }

    /** Porcentajes RG 5211: 25% + 9×8.33% = 99.97%; ajustamos el último para cerrar 100%. */
    private function porcentajes(): array
    {
        $pcts = [25.00];
        for ($i = 0; $i < 8; $i++) {
            $pcts[] = 8.33;
        }
        $pcts[] = round(100 - array_sum($pcts), 2); // 8.36 ≈
        return $pcts;
    }

    /** Distribuye `base` según porcentajes, ajustando el último para cerrar sin errores de redondeo. */
    private function distribuir(float $base, array $pcts): array
    {
        $montos = [];
        $acum = 0.0;
        for ($i = 0; $i < 9; $i++) {
            $montos[$i] = round($base * $pcts[$i] / 100, 2);
            $acum += $montos[$i];
        }
        $montos[9] = round($base - $acum, 2);   // cierra diferencia
        return $montos;
    }

    private function vencimiento(Ejercicio $ejerc, int $nro): string
    {
        $identifier = 'ANT'.str_pad((string) $nro, 2, '0', STR_PAD_LEFT);
        $anio = (int) $ejerc->fecha_inicio->format('Y');

        $fecha = DB::table('erp_calendario_vencimientos')
            ->where('anio', $anio)
            ->where('impuesto', 'GAN_ANTICIPO')
            ->where('periodo_identificador', $identifier)
            ->whereNull('terminacion_cuit')
            ->value('fecha_vencimiento');

        if ($fecha) {
            return Carbon::parse($fecha)->toDateString();
        }

        // Fallback: los anticipos arrancan ~6 meses después del cierre y se
        // espacian bimestralmente. Ejercicio cerrado 31/12 → ANT01 agosto,
        // ANT10 mayo del año siguiente. Aproximación simple:
        return Carbon::create($anio, min(12, 7 + ($nro - 1) * 1), 15)->toDateString();
    }

    private function ejercicioSiguiente(Ejercicio $ejerc): Ejercicio
    {
        $siguiente = Ejercicio::where('empresa_id', $ejerc->empresa_id)
            ->where('fecha_inicio', '>', $ejerc->fecha_cierre)
            ->orderBy('fecha_inicio')
            ->first();

        if (! $siguiente) {
            throw new DomainException(
                "GAN_EJERCICIO_SIGUIENTE_NO_EXISTE: crear el ejercicio post-{$ejerc->fecha_cierre->format('Y-m-d')} primero"
            );
        }

        return $siguiente;
    }
}
