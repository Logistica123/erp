<?php

namespace App\Erp\Services\Impuestos;

use Illuminate\Support\Facades\DB;

/**
 * Propone retenciones a aplicar a una Orden de Pago según condición IVA
 * del proveedor, naturaleza del servicio y jurisdicción.
 *
 * NO persiste — devuelve array de propuestas que `RetencionService`
 * convierte en certificados.
 *
 * Reglas operativas (RN-49, RN-50):
 *   - IVA RG 2854: aplica si proveedor RI. Régimen según naturaleza:
 *       SERVICIOS → 002 (21%)
 *       OBRA      → 003 (10.5%)
 *       BIENES    → 001 (21%)
 *   - GAN RG 830: aplica si proveedor RI o MT. Régimen según naturaleza:
 *       TRANSPORTE → 118 (0.6%)
 *       OTRO       → 116 (2%)
 *   - IIBB: aplica si caller pasa jurisdicción. CABA → 78 (2%), PBA → 79 (4%).
 *   - SUSS: 771 — opcional, sólo si caller lo solicita.
 *
 * Mínimo no retenido (RN-50): si `base < regimen.minimo_no_ret`, no se
 * propone retención (queda log "exención por mínimo" externo).
 */
class RetencionCalculator
{
    /**
     * @param array{
     *   monto_pago: float,
     *   condicion_iva: string,   // RI, MT, EX, CF
     *   naturaleza?: string,     // SERVICIOS|OBRA|BIENES|TRANSPORTE
     *   jurisdiccion?: ?string,  // CABA|PBA|null
     *   incluir_suss?: bool,
     * } $contexto
     *
     * @return array<int, array{tipo:string, regimen:string, descripcion:string,
     *   base_imponible:float, alicuota:float, importe:float, motivo_no_aplica?:string}>
     */
    public function proponer(array $contexto): array
    {
        $monto = (float) $contexto['monto_pago'];
        $condIva = strtoupper((string) $contexto['condicion_iva']);
        $naturaleza = strtoupper((string) ($contexto['naturaleza'] ?? 'SERVICIOS'));
        $jurisdiccion = $contexto['jurisdiccion'] ?? null;
        $incluirSuss = (bool) ($contexto['incluir_suss'] ?? false);

        $propuestas = [];

        // ----- IVA -----
        if ($condIva === 'RI') {
            $regIva = match ($naturaleza) {
                'OBRA' => '003',
                'BIENES' => '001',
                default => '002',
            };
            $propuestas[] = $this->aplicar('IVA', $regIva, $monto);
        }

        // ----- Ganancias -----
        if (in_array($condIva, ['RI', 'MT'], true)) {
            $regGan = $naturaleza === 'TRANSPORTE' ? '118' : '116';
            $propuestas[] = $this->aplicar('GAN', $regGan, $monto);
        }

        // ----- IIBB -----
        if ($jurisdiccion === 'CABA') {
            $propuestas[] = $this->aplicar('IIBB', '78', $monto);
        } elseif ($jurisdiccion === 'PBA') {
            $propuestas[] = $this->aplicar('IIBB', '79', $monto);
        }

        // ----- SUSS (opcional) -----
        if ($incluirSuss) {
            $propuestas[] = $this->aplicar('SUSS', '767', $monto);
        }

        // Filtrar las que no aplican (motivo_no_aplica) si el caller no las
        // quiere ver. Dejamos todo en el output para auditoría/UX.
        return array_values(array_filter($propuestas));
    }

    /**
     * Resuelve un régimen y aplica si supera el mínimo. Si la base es menor
     * al mínimo, devuelve propuesta con motivo_no_aplica para auditoría.
     */
    private function aplicar(string $tipo, string $codigo, float $base): ?array
    {
        $row = DB::table('erp_regimenes_retencion')
            ->where('codigo', $codigo)
            ->where('tipo', $tipo)
            ->where('activo', 1)
            ->where('vigente_desde', '<=', now())
            ->where(function ($q) {
                $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', now());
            })
            ->orderByDesc('vigente_desde')
            ->first(['codigo', 'descripcion', 'minimo_no_ret', 'alicuota']);

        if (! $row) {
            return null;
        }

        if ($base < (float) $row->minimo_no_ret) {
            return [
                'tipo' => $tipo, 'regimen' => $codigo, 'descripcion' => $row->descripcion,
                'base_imponible' => $base, 'alicuota' => (float) $row->alicuota, 'importe' => 0.0,
                'motivo_no_aplica' => sprintf(
                    'base %.2f < mínimo %.2f (RN-50)', $base, (float) $row->minimo_no_ret
                ),
            ];
        }

        return [
            'tipo' => $tipo, 'regimen' => $codigo, 'descripcion' => $row->descripcion,
            'base_imponible' => $base, 'alicuota' => (float) $row->alicuota,
            'importe' => round($base * (float) $row->alicuota, 2),
        ];
    }
}
