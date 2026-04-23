<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generador del archivo SIRE (Sistema Integral de Retenciones Electrónicas
 * RG 3685/2014) para importar al portal SIRE-IVA del AFIP.
 *
 * Layout simplificado pipe-delimited (verificar contra spec AFIP vigente
 * antes de uso productivo):
 *   CUIT_RETENIDO|FECHA_EMISION|TIPO_CBTE|NRO_CBTE|REGIMEN|BASE|ALIC|IMPORTE|NRO_CERT
 *
 * Una fila por retención EMITIDA del período. Las ANULADAS quedan en un
 * archivo paralelo `_anulaciones.txt`.
 *
 * MVP — IMPORTANTE
 * ----------------
 * El formato exacto del SIRE varía por versión y por tipo de retención
 * (IVA, GAN, IIBB usan layouts levemente distintos). Esta implementación
 * cubre la mayoría de los campos críticos pero requiere ajuste contra
 * la RG 3685 vigente y certificación contra acuse real.
 */
class SireGeneratorService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Genera archivos SIRE por tipo (IVA, GAN, IIBB, SUSS) del período.
     *
     * @return array<string, array{path:string, hash:string, filas:int}>
     */
    public function generar(PeriodoFiscal $periodo, User $usuario): array
    {
        if ($periodo->impuesto !== 'SICORE') {
            throw new DomainException('SIRE_PERIODO_INVALIDO: solo períodos SICORE');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("SIRE_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $resultados = [];
        foreach (['IVA', 'GAN', 'IIBB', 'SUSS'] as $tipo) {
            $rows = $this->retenciones($periodo, $tipo, 'EMITIDO');
            if ($rows->isEmpty()) {
                continue;
            }
            $contenido = $this->renderTxt($rows, $tipo);
            $hash = hash('sha256', $contenido);
            $path = $this->pathArchivo($periodo, $tipo);
            Storage::disk('local')->put($path, $contenido);
            $resultados[$tipo] = ['path' => $path, 'hash' => $hash, 'filas' => $rows->count()];
        }

        $this->audit->log('generar_sire', $periodo, null, $resultados,
            "SIRE generado por user #{$usuario->id} para período SICORE {$periodo->anio}/{$periodo->mes}");

        return $resultados;
    }

    private function retenciones(PeriodoFiscal $periodo, string $tipo, string $estado)
    {
        return DB::table('erp_retenciones_practicadas as r')
            ->leftJoin('erp_facturas_compra as f', 'f.id', '=', 'r.factura_compra_id')
            ->leftJoin('erp_tipos_comprobante as t', 't.id', '=', 'f.tipo_comprobante_id')
            ->where('r.periodo_id', $periodo->id)
            ->where('r.tipo_retencion', $tipo)
            ->where('r.estado', $estado)
            ->orderBy('r.fecha_emision')
            ->orderBy('r.nro_certificado')
            ->select([
                'r.id', 'r.cuit_retenido', 'r.fecha_emision', 'r.regimen',
                'r.base_imponible', 'r.alicuota', 'r.importe_retenido',
                'r.nro_certificado', 'r.comprobante_origen',
                't.codigo_interno as tipo_cbte_codigo', 'f.numero as cbte_numero',
            ])
            ->get();
    }

    private function renderTxt($rows, string $tipo): string
    {
        $sep = '|';
        $lineas = [];
        foreach ($rows as $r) {
            $tipoCbte = $this->codigoTipoCbte($r->tipo_cbte_codigo);
            $nroCbte  = $r->cbte_numero ?? 0;
            $lineas[] = implode($sep, [
                str_pad((string) $r->cuit_retenido, 11, '0', STR_PAD_LEFT),
                date('Ymd', strtotime((string) $r->fecha_emision)),
                str_pad((string) $tipoCbte, 3, '0', STR_PAD_LEFT),
                str_pad((string) $nroCbte, 12, '0', STR_PAD_LEFT),
                $r->regimen,
                number_format((float) $r->base_imponible, 2, '.', ''),
                number_format((float) $r->alicuota * 100, 2, '.', ''),
                number_format((float) $r->importe_retenido, 2, '.', ''),
                $r->nro_certificado,
            ]);
        }
        return implode("\r\n", $lineas)."\r\n";
    }

    private function pathArchivo(PeriodoFiscal $periodo, string $tipo): string
    {
        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        return "sire/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/SIRE_{$tipo}.txt";
    }

    private function codigoTipoCbte(?string $tipoCodigo): int
    {
        if ($tipoCodigo === null) {
            return 0;
        }
        if (preg_match('/^\d+$/', $tipoCodigo)) {
            return (int) $tipoCodigo;
        }
        return match ($tipoCodigo) {
            'FA' => 1,  'NDA' => 2, 'NCA' => 3,
            'FB' => 6,  'NDB' => 7, 'NCB' => 8,
            'FC' => 11, 'NDC' => 12,'NCC' => 13,
            default => 0,
        };
    }
}
