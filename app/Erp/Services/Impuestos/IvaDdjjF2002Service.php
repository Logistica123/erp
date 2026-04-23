<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IvaDdjj;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generador del archivo F.2002 (DDJJ IVA) importable al aplicativo AFIP.
 *
 * MVP — IMPORTANTE
 * ----------------
 * El aplicativo F.2002 acepta importación de un TXT/JSON con los renglones
 * de la DDJJ. La especificación exacta vive en el manual del aplicativo y
 * varía con cada versión. Esta implementación produce un TXT con un
 * conjunto de líneas estructuradas (clave=valor por línea) que cubre:
 *   - Cabecera del período
 *   - Débito por alícuota (5 líneas)
 *   - Crédito por alícuota (5 líneas)
 *   - Saldos y pagos a cuenta
 *
 * Antes de uso productivo, hay que ajustar al formato exacto que pide el
 * aplicativo F.2002 vigente y certificar contra un acuse real.
 *
 * Reglas:
 *   - Período debe estar ABIERTO o EN_REVISION.
 *   - El cálculo `IvaDdjjCalculator::calcular` se ejecuta antes para
 *     garantizar que el snapshot esté actualizado.
 */
class IvaDdjjF2002Service
{
    public function __construct(
        private readonly IvaDdjjCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path:string, hash:string, importe_a_pagar:float}
     */
    public function generar(PeriodoFiscal $periodo, User $usuario, float $pagosACuenta = 0.0): array
    {
        if (! $periodo->esEditable()) {
            throw new DomainException("F2002_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $ddjj = $this->calculator->calcular($periodo, $usuario, $pagosACuenta);
        $cabeceraVentas  = $periodo->fresh()->libroIvaVentas;
        $cabeceraCompras = $periodo->fresh()->libroIvaCompras;

        $contenido = $this->renderTxt($periodo, $ddjj, $cabeceraVentas, $cabeceraCompras);
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo);

        Storage::disk('local')->put($path, $contenido);

        DB::table('erp_iva_ddjj')
            ->where('periodo_id', $periodo->id)
            ->update([
                'archivo_f2002_path' => $path,
                'archivo_f2002_hash' => $hash,
                'generado_at'        => now(),
            ]);

        $this->audit->log('generar_f2002', $periodo, null, [
            'path' => $path, 'hash' => $hash, 'a_pagar' => (float) $ddjj->importe_a_pagar,
        ], "F.2002 generado por user #{$usuario->id} para período {$periodo->anio}/{$periodo->mes}");

        return [
            'path' => $path, 'hash' => $hash,
            'importe_a_pagar' => (float) $ddjj->importe_a_pagar,
        ];
    }

    private function renderTxt(PeriodoFiscal $periodo, IvaDdjj $ddjj, $venc, $comc): string
    {
        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        $L = function (string $clave, $valor) {
            return $clave.'='.(is_float($valor) || is_int($valor)
                ? number_format((float) $valor, 2, '.', '')
                : (string) $valor);
        };

        $lines = [
            '# F.2002 DDJJ IVA — generado por ERP Logística Argentina SRL',
            $L('PERIODO',          "{$periodo->anio}{$mes}"),
            $L('IMPUESTO',         '30'),  // código DDJJ IVA
            // Débito fiscal por alícuota
            $L('DEBITO_NETO_21',   $venc->neto_gravado_21),
            $L('DEBITO_IVA_21',    $venc->iva_21),
            $L('DEBITO_NETO_10_5', $venc->neto_gravado_10_5),
            $L('DEBITO_IVA_10_5',  $venc->iva_10_5),
            $L('DEBITO_NETO_27',   $venc->neto_gravado_27),
            $L('DEBITO_IVA_27',    $venc->iva_27),
            $L('DEBITO_NETO_5',    $venc->neto_gravado_5),
            $L('DEBITO_IVA_5',     $venc->iva_5),
            $L('DEBITO_NETO_2_5',  $venc->neto_gravado_2_5),
            $L('DEBITO_IVA_2_5',   $venc->iva_2_5),
            $L('DEBITO_NO_GRAVADO',$venc->neto_no_gravado),
            $L('DEBITO_EXENTO',    $venc->neto_exento),
            $L('DEBITO_TOTAL',     $ddjj->debito_fiscal),
            // Crédito fiscal por alícuota
            $L('CREDITO_NETO_21',   $comc->neto_gravado_21),
            $L('CREDITO_IVA_21',    $comc->iva_21),
            $L('CREDITO_NETO_10_5', $comc->neto_gravado_10_5),
            $L('CREDITO_IVA_10_5',  $comc->iva_10_5),
            $L('CREDITO_NETO_27',   $comc->neto_gravado_27),
            $L('CREDITO_IVA_27',    $comc->iva_27),
            $L('CREDITO_NETO_5',    $comc->neto_gravado_5),
            $L('CREDITO_IVA_5',     $comc->iva_5),
            $L('CREDITO_NETO_2_5',  $comc->neto_gravado_2_5),
            $L('CREDITO_IVA_2_5',   $comc->iva_2_5),
            $L('CREDITO_TOTAL',     $ddjj->credito_fiscal),
            // Saldos
            $L('SALDO_TECNICO',          $ddjj->saldo_tecnico),
            $L('SALDO_LIBRE_DISP_ANT',   $ddjj->saldo_libre_disp_anterior),
            $L('PERCEPCIONES_SUFRIDAS',  $ddjj->percepciones_sufridas),
            $L('RETENCIONES_SUFRIDAS',   $ddjj->retenciones_sufridas),
            $L('PAGOS_A_CUENTA',         $ddjj->pagos_a_cuenta),
            $L('SALDO_LIBRE_DISP_FINAL', $ddjj->saldo_libre_disp_final),
            $L('IMPORTE_A_PAGAR',        $ddjj->importe_a_pagar),
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    private function pathArchivo(PeriodoFiscal $periodo): string
    {
        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        return "iva-ddjj/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/F2002.txt";
    }
}
