<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generador del archivo Libro IVA Digital F.8001 (RG 4597).
 *
 * El archivo es un TXT posicional con CRLF. Estructura simplificada:
 *   • REGISTRO_VENTAS_CBTE      (1 línea por comprobante de venta)
 *   • REGISTRO_VENTAS_ALICUOTA  (1 línea por par comprobante×alícuota)
 *   • REGISTRO_COMPRAS_CBTE     (1 línea por comprobante de compra)
 *   • REGISTRO_COMPRAS_ALICUOTA (1 línea por par comprobante×alícuota)
 *
 * MVP — IMPORTANTE
 * ----------------
 * Las posiciones exactas de cada campo están especificadas en el documento
 * AFIP "Régimen Libro IVA Digital — Especificaciones Técnicas". El formato
 * y longitudes pueden cambiar entre versiones; antes de producción **es
 * obligatorio** validar el TXT generado contra:
 *   1. La RG vigente al período de presentación.
 *   2. Un fixture de acuse real validado por AFIP.
 *
 * Esta implementación cubre la estructura correcta a nivel de tipos y orden
 * de campos pero no garantiza paridad exacta posición-por-posición. Tests
 * golden contra fixture certificado: ver tests/Feature/Impuestos.
 *
 * Reglas:
 *   - RN-45: si el período está APROBADO/PRESENTADO/CERRADO, falla con error.
 *   - El archivo se guarda en disk 'local' bajo libro-iva/{empresa}/{anio}-{mes}.
 *   - El hash SHA-256 del contenido queda en la cabecera para auditoría.
 */
class LibroIvaF8001Service
{
    public function __construct(
        private readonly LibroIvaService $libroIva,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Genera los dos archivos (ventas y compras) y devuelve sus paths.
     *
     * @return array{ventas_path:string, ventas_hash:string,
     *               compras_path:string, compras_hash:string}
     */
    public function generar(PeriodoFiscal $periodo, User $usuario): array
    {
        if ($periodo->impuesto !== 'IVA') {
            throw new DomainException('LIBRO_IVA_F8001_PERIODO_INVALIDO: solo IVA');
        }

        if (! $periodo->esEditable()) {
            throw new DomainException(
                "LIBRO_IVA_F8001_PERIODO_NO_EDITABLE: estado {$periodo->estado} (RN-45)"
            );
        }

        // Garantizar que el detalle esté armado y refrescado.
        $this->libroIva->armar($periodo, $usuario);

        $detalle = $this->libroIva->detalle($periodo);
        [$ventasPath, $ventasHash]   = $this->generarArchivoVentas($periodo, $detalle['ventas'], $usuario);
        [$comprasPath, $comprasHash] = $this->generarArchivoCompras($periodo, $detalle['compras'], $usuario);

        $this->audit->log('generar_f8001', $periodo, null, [
            'ventas_path'  => $ventasPath, 'ventas_hash'  => $ventasHash,
            'compras_path' => $comprasPath, 'compras_hash' => $comprasHash,
        ], "F.8001 generado por user #{$usuario->id} para período {$periodo->anio}/{$periodo->mes}");

        return [
            'ventas_path'  => $ventasPath,  'ventas_hash'  => $ventasHash,
            'compras_path' => $comprasPath, 'compras_hash' => $comprasHash,
        ];
    }

    private function generarArchivoVentas(PeriodoFiscal $periodo, $facturas, User $usuario): array
    {
        $lineas = [];
        foreach ($facturas as $f) {
            $lineas[] = $this->lineaVentaCbte($f);
            foreach ($this->ivaPorFactura('erp_factura_venta_iva', $f->id) as $iva) {
                $lineas[] = $this->lineaVentaAlicuota($f, $iva);
            }
        }
        $contenido = implode("\r\n", $lineas)."\r\n";
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo, 'ventas');

        Storage::disk('local')->put($path, $contenido);

        DB::table('erp_libro_iva_ventas_periodo')
            ->where('periodo_id', $periodo->id)
            ->update([
                'archivo_f8001_path' => $path,
                'archivo_f8001_hash' => $hash,
                'generado_at'        => now(),
                'generado_user_id'   => $usuario->id,
            ]);

        return [$path, $hash];
    }

    private function generarArchivoCompras(PeriodoFiscal $periodo, $facturas, User $usuario): array
    {
        $lineas = [];
        foreach ($facturas as $f) {
            $lineas[] = $this->lineaCompraCbte($f);
            foreach ($this->ivaPorFactura('erp_factura_compra_iva', $f->id) as $iva) {
                $lineas[] = $this->lineaCompraAlicuota($f, $iva);
            }
        }
        $contenido = implode("\r\n", $lineas)."\r\n";
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($periodo, 'compras');

        Storage::disk('local')->put($path, $contenido);

        DB::table('erp_libro_iva_compras_periodo')
            ->where('periodo_id', $periodo->id)
            ->update([
                'archivo_f8001_path' => $path,
                'archivo_f8001_hash' => $hash,
                'generado_at'        => now(),
                'generado_user_id'   => $usuario->id,
            ]);

        return [$path, $hash];
    }

    private function ivaPorFactura(string $tabla, int $facturaId)
    {
        return DB::table($tabla.' as fi')
            ->join('erp_alicuotas_iva as a', 'a.id', '=', 'fi.alicuota_iva_id')
            ->where('fi.factura_id', $facturaId)
            ->select(['a.codigo_interno', 'a.tasa', 'fi.base_imponible', 'fi.importe_iva'])
            ->orderBy('a.codigo_interno')
            ->get();
    }

    /**
     * Línea de comprobante de VENTA (RG 4597 — registro de comprobantes).
     * Layout aproximado (verificar contra spec AFIP vigente):
     *   pos 1-8     fecha (AAAAMMDD)
     *   pos 9-11    tipo comprobante (3 dig, padded zero)
     *   pos 12-16   punto venta (5 dig)
     *   pos 17-36   nro comprobante desde (20 dig)
     *   pos 37-56   nro comprobante hasta (20 dig)
     *   pos 57-58   código documento receptor (2 dig: 80 CUIT, 96 DNI, ...)
     *   pos 59-78   nro documento (20 dig)
     *   pos 79-108  apellido y nombre / razón social (30 chars)
     *   pos 109-123 importe total (15 dig, 2 dec implícitos)
     *   ...
     */
    private function lineaVentaCbte(object $f): string
    {
        return implode('', [
            $this->fmtFecha($f->fecha_emision),                                      //  8
            $this->fmtNum($this->codigoTipoCbte($f->tipo_codigo, $f->letra), 3),    //  3
            $this->fmtNum($f->pto_vta, 5),                                          //  5
            $this->fmtNum($f->numero, 20),                                          // 20
            $this->fmtNum($f->numero, 20),                                          // 20  (cbte_hasta = cbte_desde para compr individuales)
            $this->fmtNum($this->codigoDocAfip($f->doc_tipo_afip), 2),              //  2
            $this->fmtNum(preg_replace('/\D/', '', (string) $f->doc_nro), 20),      // 20
            $this->fmtTxt($f->razon_social, 30),                                    // 30
            $this->fmtImp($f->imp_total),                                           // 15
            $this->fmtImp($f->imp_no_gravado),                                      // 15
            $this->fmtImp($f->imp_exento),                                          // 15
            $this->fmtImp(0),                                                       // 15  imp_op_exentas
            $this->fmtImp(0),                                                       // 15  imp_perc_no_categ
            $this->fmtImp(0),                                                       // 15  imp_perc_iibb
            $this->fmtImp(0),                                                       // 15  imp_perc_municipales
            $this->fmtImp(0),                                                       // 15  imp_internos
            'PES',                                                                  //  3  moneda
            $this->fmtNum(10000, 10, 6),                                            // 10  cotizacion (1.000000)
            $this->fmtNum(1, 1),                                                    //  1  cantidad alicuotas
            'A',                                                                    //  1  cod operacion
            $this->fmtImp(0),                                                       // 15  otros tributos
            $this->fmtFecha($f->fecha_vto_cae ?? $f->fecha_emision),                //  8  fecha vto pago
            $this->fmtTxt($f->cae ?? '', 14),                                       // 14  CAE
        ]);
    }

    private function lineaVentaAlicuota(object $f, object $iva): string
    {
        return implode('', [
            $this->fmtNum($this->codigoTipoCbte($f->tipo_codigo, $f->letra), 3),
            $this->fmtNum($f->pto_vta, 5),
            $this->fmtNum($f->numero, 20),
            $this->fmtImp($iva->base_imponible),
            $this->fmtNum($this->codigoAlicuota((float) $iva->tasa), 4),
            $this->fmtImp($iva->importe_iva),
        ]);
    }

    private function lineaCompraCbte(object $f): string
    {
        return implode('', [
            $this->fmtFecha($f->fecha_emision),
            $this->fmtNum($this->codigoTipoCbte($f->tipo_codigo, $f->letra), 3),
            $this->fmtNum($f->pto_vta, 5),
            $this->fmtNum($f->numero, 20),
            $this->fmtTxt('', 16),                                                   // despacho importacion (vacío)
            $this->fmtNum(80, 2),                                                    // doc tipo: CUIT
            $this->fmtNum(preg_replace('/\D/', '', (string) $f->cuit_emisor), 20),
            $this->fmtTxt($f->razon_social, 30),
            $this->fmtImp($f->imp_total),
            $this->fmtImp($f->imp_no_gravado),
            $this->fmtImp($f->imp_exento),
            $this->fmtImp($f->imp_percepciones),                                     // perc IVA sufridas
            $this->fmtImp(0),                                                        // perc IIBB
            $this->fmtImp(0),                                                        // perc municipales
            $this->fmtImp(0),                                                        // imp internos
            'PES',
            $this->fmtNum(10000, 10, 6),
            $this->fmtNum(1, 1),
            $this->fmtImp(0),                                                        // otros tributos
            $this->fmtTxt($f->cae ?? '', 14),
        ]);
    }

    private function lineaCompraAlicuota(object $f, object $iva): string
    {
        return implode('', [
            $this->fmtNum($this->codigoTipoCbte($f->tipo_codigo, $f->letra), 3),
            $this->fmtNum($f->pto_vta, 5),
            $this->fmtNum($f->numero, 20),
            $this->fmtNum(80, 2),
            $this->fmtNum(preg_replace('/\D/', '', (string) $f->cuit_emisor), 20),
            $this->fmtImp($iva->base_imponible),
            $this->fmtNum($this->codigoAlicuota((float) $iva->tasa), 4),
            $this->fmtImp($iva->importe_iva),
        ]);
    }

    // ------------------------------------------------------------------------
    // Helpers de formato
    // ------------------------------------------------------------------------

    private function pathArchivo(PeriodoFiscal $periodo, string $tipo): string
    {
        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        return "libro-iva/{$periodo->empresa_id}/{$periodo->anio}-{$mes}/F8001_{$tipo}.txt";
    }

    private function fmtFecha($valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Ymd');
        }
        return date('Ymd', strtotime((string) $valor));
    }

    private function fmtNum(int|string|null $valor, int $longitud, int $decimales = 0): string
    {
        $n = (int) ($decimales > 0 ? round(((float) $valor) * (10 ** $decimales)) : ($valor ?? 0));
        return str_pad((string) $n, $longitud, '0', STR_PAD_LEFT);
    }

    /** Importes se exportan con 2 decimales implícitos en 15 dígitos. */
    private function fmtImp(float|int|string|null $valor): string
    {
        $cents = (int) round(((float) $valor) * 100);
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign.str_pad((string) $abs, 15 - strlen($sign), '0', STR_PAD_LEFT);
    }

    private function fmtTxt(?string $valor, int $longitud): string
    {
        $v = mb_substr((string) $valor, 0, $longitud);
        // Reemplazar acentos para compatibilidad ASCII / latin-1.
        $v = strtr($v, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
            'ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U',
        ]);
        return str_pad($v, $longitud, ' ', STR_PAD_RIGHT);
    }

    /**
     * Mapea (tipo_interno, letra) → código AFIP. Los códigos canónicos están
     * en `erp_tipos_comprobante.codigo_interno`; muchos ya vienen como número.
     * Si `codigo_interno` es numérico, lo usamos directo; si no, fallback a 0.
     */
    private function codigoTipoCbte(?string $tipoCodigo, ?string $letra): int
    {
        if ($tipoCodigo === null) {
            return 0;
        }
        if (preg_match('/^\d+$/', $tipoCodigo)) {
            return (int) $tipoCodigo;
        }
        // Mapeo de fallback para códigos textuales habituales.
        $map = [
            'FA' => 1,  'NDA' => 2,  'NCA' => 3,
            'FB' => 6,  'NDB' => 7,  'NCB' => 8,
            'FC' => 11, 'NDC' => 12, 'NCC' => 13,
        ];
        return $map[$tipoCodigo] ?? 0;
    }

    private function codigoDocAfip(?int $tipo): int
    {
        // 80 = CUIT, 86 = CUIL, 96 = DNI, 99 = SinIdentificar
        return $tipo ?? 99;
    }

    /** AFIP usa códigos para alícuotas: 3=0%, 4=10.5%, 5=21%, 6=27%, 8=5%, 9=2.5%. */
    private function codigoAlicuota(float $tasa): int
    {
        return match (true) {
            abs($tasa - 0.21)  < 0.001 => 5,
            abs($tasa - 0.105) < 0.001 => 4,
            abs($tasa - 0.27)  < 0.001 => 6,
            abs($tasa - 0.05)  < 0.001 => 8,
            abs($tasa - 0.025) < 0.001 => 9,
            abs($tasa - 0.0)   < 0.001 => 3,
            default                    => 0,
        };
    }
}
