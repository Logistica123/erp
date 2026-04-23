<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use Illuminate\Support\Facades\DB;

/**
 * Materializa percepciones sufridas (las que nos practicaron) desde la
 * tabla `erp_factura_compra_tributos` en `erp_percepciones_sufridas`,
 * agregadas por período fiscal IVA. Sirven como pago a cuenta en la
 * DDJJ F.2002 (RN-51 + spec §5).
 *
 * Mapeo de tipo_tributo.codigo_interno → tipo de percepción:
 *   PERC_IVA      → IVA
 *   PERC_IIBB_*   → IIBB_CABA / IIBB_PBA / IIBB_CM (según jurisdicción)
 *   PERC_GAN      → GAN
 *   PERC_SUSS     → SUSS
 *   PERC_INT      → IMPUESTO_INT
 *
 * Idempotente: borra todas las filas del período antes de reinsertar.
 */
class PercepcionesSufridasService
{
    /** Estados de factura compra que se consideran para percepciones. */
    private const ESTADOS = ['CONTROLADA', 'PAGO_PARCIAL', 'PAGADA', 'ANULADA_POR_NC'];

    /**
     * Recalcula percepciones sufridas del período. Devuelve cantidad de filas
     * insertadas y total por tipo.
     *
     * @return array{filas:int, totales:array<string,float>}
     */
    public function recalcular(PeriodoFiscal $periodo): array
    {
        return DB::transaction(function () use ($periodo) {
            DB::table('erp_percepciones_sufridas')->where('periodo_id', $periodo->id)->delete();

            $filas = $this->fetchTributos($periodo);
            $insert = [];
            $totales = [];

            foreach ($filas as $row) {
                $tipo = $this->mapearTipo($row->codigo_interno, $row->jurisdiccion);
                if ($tipo === null) {
                    continue;
                }
                $importe = (float) $row->importe * (int) $row->signo;
                $insert[] = [
                    'factura_compra_id' => (int) $row->factura_id,
                    'tipo'              => $tipo,
                    'regimen'           => $row->codigo_interno,
                    'base'              => (float) $row->base_imponible * (int) $row->signo,
                    'alicuota'          => $row->alicuota !== null ? (float) $row->alicuota : null,
                    'importe'           => $importe,
                    'periodo_id'        => $periodo->id,
                    'created_at'        => now(),
                ];
                $totales[$tipo] = ($totales[$tipo] ?? 0) + $importe;
            }

            if (! empty($insert)) {
                DB::table('erp_percepciones_sufridas')->insert($insert);
            }

            return ['filas' => count($insert), 'totales' => $totales];
        });
    }

    /** Suma de percepciones del período por tipo. */
    public function totales(PeriodoFiscal $periodo): array
    {
        $rows = DB::table('erp_percepciones_sufridas')
            ->where('periodo_id', $periodo->id)
            ->groupBy('tipo')
            ->select('tipo', DB::raw('SUM(importe) AS total'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->tipo] = (float) $r->total;
        }
        return $out;
    }

    private function fetchTributos(PeriodoFiscal $periodo)
    {
        return DB::table('erp_factura_compra_tributos as t')
            ->join('erp_facturas_compra as f', 'f.id', '=', 't.factura_id')
            ->join('erp_tipos_tributo as tt', 'tt.id', '=', 't.tributo_id')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $periodo->empresa_id)
            ->whereYear('f.fecha_emision', $periodo->anio)
            ->whereMonth('f.fecha_emision', $periodo->mes)
            ->whereIn('f.estado', self::ESTADOS)
            ->where('tt.es_retencion', 0)
            ->where('tt.codigo_interno', 'LIKE', 'PERC_%')
            ->select([
                't.factura_id', 't.base_imponible', 't.alicuota', 't.importe',
                'tt.codigo_interno', 'tt.jurisdiccion',
                'tc.signo',
            ])
            ->get();
    }

    private function mapearTipo(string $codigo, ?string $jurisdiccion): ?string
    {
        return match ($codigo) {
            'PERC_IVA'        => 'IVA',
            'PERC_GAN'        => 'GAN',
            'PERC_SUSS'       => 'SUSS',
            'PERC_INT'        => 'IMPUESTO_INT',
            'PERC_IIBB_CABA'  => 'IIBB_CABA',
            'PERC_IIBB_PBA'   => 'IIBB_PBA',
            'PERC_IIBB_CM'    => 'IIBB_CM',
            default => match ($jurisdiccion) {
                'CABA' => str_starts_with($codigo, 'PERC_IIBB') ? 'IIBB_CABA' : null,
                'PBA'  => str_starts_with($codigo, 'PERC_IIBB') ? 'IIBB_PBA'  : null,
                default => null,
            },
        };
    }
}
