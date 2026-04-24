<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IibbCmDeclaracion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Generador SIFERE (Convenio Multilateral) + ARCIBA (CABA) + ARBA (PBA).
 *
 * Formato TXT (MVP — verificar contra aplicativo oficial antes de presentar):
 *   CUIT|PERIODO|JURISDICCION|BASE|COEFICIENTE|ALICUOTA|IMPUESTO|PERC|RET|SALDO_ANT|A_PAGAR
 *
 * Los tres aplicativos (SIFERE/ARCIBA/ARBA) usan layouts parecidos; este
 * servicio emite una variante por período según `erp_periodos_fiscales.impuesto`.
 */
class SifereGeneratorService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path:string, hash:string, filas:int}
     */
    public function generar(PeriodoFiscal $periodo, User $usuario): array
    {
        $tiposValidos = ['IIBB_CM', 'IIBB_CABA', 'IIBB_PBA'];
        if (! in_array($periodo->impuesto, $tiposValidos, true)) {
            throw new DomainException('IIBB_GEN_PERIODO_INVALIDO: impuesto debe ser '.implode('|', $tiposValidos));
        }
        if (! $periodo->esEditable()) {
            throw new DomainException("IIBB_GEN_PERIODO_NO_EDITABLE: estado {$periodo->estado}");
        }

        $filas = IibbCmDeclaracion::where('periodo_id', $periodo->id)
            ->orderBy('jurisdiccion')
            ->get();

        if ($filas->isEmpty()) {
            throw new DomainException('IIBB_SIN_DECLARACION: calcular primero');
        }

        $cuit = preg_replace('/\D/', '',
            (string) \Illuminate\Support\Facades\DB::table('erp_empresas')->where('id', $periodo->empresa_id)->value('cuit'));

        $lineas = [];
        foreach ($filas as $f) {
            $lineas[] = implode('|', [
                str_pad($cuit, 11, '0', STR_PAD_LEFT),
                sprintf('%04d%02d', $periodo->anio, $periodo->mes),
                $f->jurisdiccion,
                number_format((float) $f->base_imponible, 2, '.', ''),
                number_format((float) $f->coeficiente, 8, '.', ''),
                number_format((float) $f->alicuota * 100, 4, '.', ''),
                number_format((float) $f->impuesto_determinado, 2, '.', ''),
                number_format((float) $f->percepciones_sufridas, 2, '.', ''),
                number_format((float) $f->retenciones_sufridas, 2, '.', ''),
                number_format((float) $f->saldo_anterior, 2, '.', ''),
                number_format((float) $f->importe_a_pagar, 2, '.', ''),
            ]);
        }
        $contenido = implode("\r\n", $lineas)."\r\n";
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo);

        Storage::disk('local')->put($path, $contenido);

        IibbCmDeclaracion::where('periodo_id', $periodo->id)
            ->update(['archivo_sifere_path' => $path, 'generado_at' => now()]);

        $this->audit->log("generar_{$periodo->impuesto}", $periodo, null, [
            'path' => $path, 'hash' => $hash, 'filas' => $filas->count(),
        ], "{$periodo->impuesto} TXT generado user #{$usuario->id}");

        return ['path' => $path, 'hash' => $hash, 'filas' => $filas->count()];
    }

    private function pathArchivo(PeriodoFiscal $periodo): string
    {
        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        return "iibb/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/{$periodo->impuesto}.txt";
    }
}
