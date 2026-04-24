<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\GananciaLiquidacion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Generador del archivo F.713 (DDJJ Ganancias Personas Jurídicas).
 *
 * MVP — IMPORTANTE
 * ----------------
 * El aplicativo "Ganancias Personas Jurídicas" AFIP acepta importación
 * en formato propietario que varía con cada versión mayor. Esta
 * implementación emite un TXT clave=valor con los datos principales
 * (resultado contable, ajustes, impositivo, escala, impuesto, anticipos).
 * Antes de presentación productiva, hay que ajustar al layout exacto
 * del aplicativo vigente y certificar contra un acuse real.
 */
class GananciasF713Service
{
    public function __construct(
        private readonly GananciasCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path:string, hash:string, saldo_a_pagar:float}
     */
    public function generar(PeriodoFiscal $periodo, GananciaLiquidacion $liq, User $usuario): array
    {
        if ($periodo->id !== $liq->periodo_id) {
            throw new DomainException('F713_PERIODO_MISMATCH');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("F713_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $contenido = $this->renderTxt($periodo, $liq);
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo);

        Storage::disk('local')->put($path, $contenido);

        $liq->update([
            'archivo_f713_path' => $path,
            'archivo_f713_hash' => $hash,
            'generado_at'       => now(),
        ]);

        $this->audit->log('generar_f713', $liq, null, [
            'path' => $path, 'hash' => $hash, 'a_pagar' => (float) $liq->saldo_a_pagar,
        ], "F.713 generado para ejercicio #{$liq->ejercicio_id} (user #{$usuario->id})");

        return ['path' => $path, 'hash' => $hash, 'saldo_a_pagar' => (float) $liq->saldo_a_pagar];
    }

    private function renderTxt(PeriodoFiscal $periodo, GananciaLiquidacion $liq): string
    {
        $meta = is_array($liq->alicuota_escalonada) ? $liq->alicuota_escalonada : [];
        $L = fn (string $k, $v) => $k.'='.(is_float($v) || is_int($v)
            ? number_format((float) $v, 2, '.', '')
            : (string) $v);

        $lineas = [
            '# F.713 DDJJ Ganancias Personas Jurídicas — ERP Logística Argentina SRL',
            $L('EJERCICIO_ID', $liq->ejercicio_id),
            $L('PERIODO_ID',   $liq->periodo_id),
            $L('ANIO',         $periodo->anio),
            $L('RESULTADO_CONTABLE',        $liq->resultado_contable),
            $L('AJUSTES_FISCALES_MAS',      $liq->ajustes_fiscales_mas),
            $L('AJUSTES_FISCALES_MENOS',    $liq->ajustes_fiscales_menos),
            $L('AJUSTE_INFLACION_IMPORTE',  $liq->ajuste_inflacion_importe),
            $L('AJUSTA_POR_INFLACION',      $liq->ajusta_por_inflacion ? '1' : '0'),
            $L('RESULTADO_IMPOSITIVO',      $liq->resultado_impositivo),
            $L('IMPUESTO_DETERMINADO',      $liq->impuesto_determinado),
            $L('ANTICIPOS_COMPUTADOS',      $liq->anticipos_computados),
            $L('RETENCIONES_SUFRIDAS',      $liq->retenciones_sufridas),
            $L('PERCEPCIONES_SUFRIDAS',     $liq->percepciones_sufridas),
            $L('SALDO_A_PAGAR',             $liq->saldo_a_pagar),
            $L('SALDO_A_FAVOR',             $liq->saldo_a_favor),
        ];

        // Tramos de la escala aplicada (memoria de cálculo RN-56).
        foreach ($meta['breakdown_tramos'] ?? [] as $idx => $t) {
            $n = $idx + 1;
            $lineas[] = $L("TRAMO_{$n}_LIMITE_INFERIOR",   $t['limite_inferior']);
            $lineas[] = $L("TRAMO_{$n}_LIMITE_SUPERIOR",   $t['limite_superior'] ?? '');
            $lineas[] = $L("TRAMO_{$n}_ALICUOTA_MARGINAL", $t['alicuota_marginal']);
            $lineas[] = $L("TRAMO_{$n}_CUOTA_FIJA",        $t['cuota_fija']);
            $lineas[] = $L("TRAMO_{$n}_IMPUESTO",          $t['impuesto']);
        }

        // Ajustes (concepto por concepto).
        foreach ($meta['ajustes'] ?? [] as $idx => $a) {
            $n = $idx + 1;
            $lineas[] = $L("AJUSTE_{$n}_TIPO",     $a['tipo']);
            $lineas[] = $L("AJUSTE_{$n}_CONCEPTO", $a['concepto']);
            $lineas[] = $L("AJUSTE_{$n}_IMPORTE",  $a['importe']);
        }

        return implode("\r\n", $lineas)."\r\n";
    }

    private function pathArchivo(PeriodoFiscal $periodo): string
    {
        return "ganancias/{$periodo->empresa_id}/{$periodo->anio}/F713.txt";
    }
}
