<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\BpParticipacion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Generador del archivo F.2000 (DDJJ Bienes Personales Participaciones).
 *
 * MVP — verificar contra aplicativo "Bienes Personales — Acciones y
 * Participaciones" AFIP vigente antes de presentación productiva.
 *
 * Layout TXT clave=valor con cabecera + N líneas de socios.
 */
class BpF2000Service
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path:string, hash:string, impuesto_total:float}
     */
    public function generar(PeriodoFiscal $periodo, BpParticipacion $bp, User $usuario): array
    {
        if ($periodo->id !== $bp->periodo_id) {
            throw new DomainException('F2000_PERIODO_MISMATCH');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("F2000_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $contenido = $this->renderTxt($periodo, $bp);
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo);

        Storage::disk('local')->put($path, $contenido);

        $bp->update([
            'archivo_f2000_path' => $path,
            'archivo_f2000_hash' => $hash,
            'generado_at'        => now(),
        ]);

        $this->audit->log('generar_f2000', $bp, null, [
            'path' => $path, 'hash' => $hash, 'impuesto' => (float) $bp->impuesto_total,
        ], "F.2000 generado para ejercicio #{$bp->ejercicio_id} (user #{$usuario->id})");

        return ['path' => $path, 'hash' => $hash, 'impuesto_total' => (float) $bp->impuesto_total];
    }

    private function renderTxt(PeriodoFiscal $periodo, BpParticipacion $bp): string
    {
        $L = fn (string $k, $v) => $k.'='.(is_float($v) || is_int($v)
            ? number_format((float) $v, 2, '.', '')
            : (string) $v);

        $lineas = [
            '# F.2000 BP Participaciones — ERP Logística Argentina SRL',
            $L('EJERCICIO_ID',           $bp->ejercicio_id),
            $L('PERIODO_ID',             $bp->periodo_id),
            $L('ANIO',                   $periodo->anio),
            $L('PATRIMONIO_NETO_AJUST',  $bp->patrimonio_neto_ajustado),
            $L('ALICUOTA',               $bp->alicuota),
            $L('IMPUESTO_TOTAL',         $bp->impuesto_total),
            $L('CANTIDAD_SOCIOS',        count($bp->socios_detalle ?? [])),
        ];

        foreach ((array) $bp->socios_detalle as $idx => $s) {
            $n = $idx + 1;
            $lineas[] = $L("SOCIO_{$n}_CUIT",       $s['cuit']);
            $lineas[] = $L("SOCIO_{$n}_NOMBRE",     $s['nombre']);
            $lineas[] = $L("SOCIO_{$n}_TIPO",       $s['tipo']);
            $lineas[] = $L("SOCIO_{$n}_PORCENTAJE", $s['porcentaje_participacion']);
            $lineas[] = $L("SOCIO_{$n}_VPP",        $s['vpp']);
            $lineas[] = $L("SOCIO_{$n}_IMPUESTO",   $s['impuesto']);
        }

        return implode("\r\n", $lineas)."\r\n";
    }

    private function pathArchivo(PeriodoFiscal $periodo): string
    {
        return "bp/{$periodo->empresa_id}/{$periodo->anio}/F2000.txt";
    }
}
