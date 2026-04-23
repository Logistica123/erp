<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\RetencionPracticada;
use Illuminate\Support\Facades\DB;

/**
 * Renderiza el certificado de retención (HTML print-friendly) para
 * entregar al proveedor. El usuario imprime a PDF desde el navegador.
 *
 * Mantenemos formato HTML (no PDF) para no introducir dependencia de
 * DomPDF en H3. Cuando se necesite PDF nativo (firmable), se agrega
 * en un bloque posterior (`composer require barryvdh/laravel-dompdf`).
 */
class CertificadoRetencionService
{
    public function renderHtml(RetencionPracticada $ret): string
    {
        $empresa = DB::table('erp_empresas')->where('id', $ret->empresa_id)->first();
        $proveedor = DB::table('erp_auxiliares')->where('id', $ret->proveedor_id)->first();
        $regimen = DB::table('erp_regimenes_retencion')
            ->where('codigo', $ret->regimen)
            ->where('tipo', $ret->tipo_retencion)
            ->orderByDesc('vigente_desde')
            ->first();

        $tituloTipo = match ($ret->tipo_retencion) {
            'IVA'  => 'Retención de IVA — RG 2854/2010',
            'GAN'  => 'Retención de Ganancias — RG 830/2000',
            'IIBB' => 'Retención de IIBB',
            'SUSS' => 'Retención SUSS — RG 1784/2004',
            default => 'Retención',
        };

        $fmtImp = fn (float $n) => number_format($n, 2, ',', '.');
        $fmtCuit = fn (string $c) => preg_replace('/^(\d{2})(\d{8})(\d{1})$/', '$1-$2-$3', $c);

        $estilo = '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:11pt;color:#222;max-width:780px;margin:auto;padding:20px}
            h1{font-size:14pt;text-align:center;border-bottom:2px solid #1e3a5f;padding-bottom:6px;color:#1e3a5f}
            h2{font-size:12pt;margin-top:20px;color:#1e3a5f}
            table{width:100%;border-collapse:collapse;margin-top:8px}
            td{padding:4px 8px;border:1px solid #c8d3df;vertical-align:top}
            td.label{background:#eef3f8;width:32%;font-weight:bold}
            .num{text-align:right;font-variant-numeric:tabular-nums}
            .footer{margin-top:32px;font-size:9pt;color:#666;border-top:1px solid #c8d3df;padding-top:12px}
            .estado-anulado{color:#b00020;font-weight:bold}
            @media print{.noprint{display:none}}
        </style>';

        $estado = $ret->estado === 'ANULADO' ? '<p class="estado-anulado">CERTIFICADO ANULADO</p>' : '';

        return $estilo
            .'<h1>Certificado de '.htmlspecialchars($tituloTipo).'</h1>'
            .$estado
            .'<table>'
            .$this->fila('Agente de retención',
                ($empresa ? htmlspecialchars($empresa->razon_social).' — CUIT '.$fmtCuit($empresa->cuit) : ''))
            .$this->fila('Sujeto retenido',
                ($proveedor ? htmlspecialchars($proveedor->nombre).' — CUIT '.$fmtCuit((string) $ret->cuit_retenido) : ''))
            .$this->fila('Fecha de emisión', $ret->fecha_emision->format('d/m/Y'))
            .$this->fila('N° de certificado', htmlspecialchars($ret->nro_certificado))
            .$this->fila('Régimen', $ret->regimen.($regimen ? ' — '.htmlspecialchars($regimen->descripcion) : ''))
            .'</table>'
            .'<h2>Cálculo</h2>'
            .'<table>'
            .$this->fila('Base imponible', '$ '.$fmtImp((float) $ret->base_imponible), 'num')
            .$this->fila('Alícuota', number_format(((float) $ret->alicuota) * 100, 2, ',', '.').' %', 'num')
            .$this->fila('Importe retenido', '<b>$ '.$fmtImp((float) $ret->importe_retenido).'</b>', 'num')
            .'</table>'
            .'<div class="footer">Este certificado es válido como comprobante de la retención practicada. '
            .'Generado por ERP Logística Argentina SRL el '.now()->format('d/m/Y H:i').' hs.</div>'
            .'<p class="noprint" style="margin-top:24px;text-align:center">'
            .'<button onclick="window.print()">Imprimir / Guardar PDF</button></p>';
    }

    private function fila(string $label, string $valor, string $extraClassValor = ''): string
    {
        return '<tr><td class="label">'.htmlspecialchars($label).'</td>'
            .'<td'.($extraClassValor ? ' class="'.$extraClassValor.'"' : '').'>'.$valor.'</td></tr>';
    }
}
