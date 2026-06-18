<?php

namespace App\Erp\Services\Seguros;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\FacturaCompraService;
use App\Erp\Services\GeneradorF8001Service;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento de Seguro (Compras): toma un PDF de póliza, extrae el detalle
 * de facturación, lo carga como comprobante en el Libro IVA Compras y emite el
 * TXT del Libro IVA Digital — sin consumir/autogenerar numeración (el número de
 * comprobante lo define el contador) ni registrar una generación F.8001.
 */
class ProcesamientoSeguroService
{
    public function __construct(
        private readonly ParserSeguroFactory $factory,
        private readonly FacturaCompraService $facturaSvc,
        private readonly GeneradorF8001Service $generador,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Extrae el texto del PDF y devuelve el detalle parseado (preview, sin DB).
     * @return array<string,mixed>
     */
    public function analizar(string $pathPdf): array
    {
        $texto = $this->extraerTexto($pathPdf);
        if (trim($texto) === '') {
            throw new DomainException('PDF_SIN_TEXTO: el PDF parece escaneado (sin texto). Por ahora se soportan PDFs con texto.');
        }
        $parser = $this->factory->resolver($texto);
        return $parser->parse($texto);
    }

    /**
     * Carga el comprobante de seguro en el Libro IVA Compras.
     * $data: campos revisados (incluye punto_venta y numero del contador).
     */
    public function cargar(array $data, User $usuario, int $empresaId = 1): FacturaCompra
    {
        foreach (['cuit_aseguradora', 'fecha_emision', 'punto_venta', 'numero', 'tipo_comprobante_id', 'imp_total'] as $req) {
            if (empty($data[$req]) && $data[$req] !== 0 && $data[$req] !== '0') {
                throw new DomainException("CAMPO_REQUERIDO: falta {$req}");
            }
        }
        $cuit = preg_replace('/\D/', '', (string) $data['cuit_aseguradora']);
        $pv = (int) $data['punto_venta'];
        $numero = (int) $data['numero'];
        $tipo = (int) $data['tipo_comprobante_id']; // 90 baja / 99 alta

        // Idempotencia: no duplicar el mismo comprobante.
        $dup = FacturaCompra::where('empresa_id', $empresaId)
            ->where('cuit_emisor', $cuit)->where('tipo_comprobante_id', $tipo)
            ->where('punto_venta', $pv)->where('numero', $numero)
            ->whereNull('deleted_at')->first();
        if ($dup) {
            throw new DomainException("COMPROBANTE_DUPLICADO: ya existe (factura compra #{$dup->id}).");
        }

        $neto21  = round((float) ($data['imp_neto_gravado_21'] ?? 0), 2);
        $iva21   = round((float) ($data['imp_iva_21'] ?? 0), 2);
        $percIva = round((float) ($data['imp_percepciones_iva'] ?? 0), 2);
        $otrosTr = round((float) ($data['imp_otros_tributos'] ?? 0), 2);
        $total   = round((float) $data['imp_total'], 2);

        return DB::transaction(function () use ($data, $usuario, $empresaId, $cuit, $pv, $numero, $tipo, $neto21, $iva21, $percIva, $otrosTr, $total) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $auxId = $this->upsertProveedor($empresaId, $cuit, (string) ($data['aseguradora'] ?? ''));
            $imp = $this->facturaSvc->resolverImputacion(
                $data['fecha_emision'], $data['fecha_imputacion'] ?? null, $usuario, $empresaId
            );

            $factura = FacturaCompra::create([
                'empresa_id' => $empresaId,
                'tipo_comprobante_id' => $tipo,
                'punto_venta' => $pv, 'numero' => $numero,
                'fecha_emision' => $data['fecha_emision'],
                'fecha_recepcion' => $data['fecha_emision'],
                'fecha_imputacion' => $imp['fecha_imputacion'],
                'periodo_id' => $imp['periodo_id'],
                'imputacion_diferida' => $imp['imputacion_diferida'],
                'auxiliar_id' => $auxId,
                'cuit_emisor' => $cuit,
                'razon_social_emisor' => $data['aseguradora'] ?? null,
                'condicion_iva_id' => 1, 'moneda_id' => 1, 'cotizacion' => 1.0,
                'imp_neto_gravado' => $neto21, 'imp_neto_gravado_21' => $neto21,
                'imp_no_gravado' => 0, 'imp_exento' => 0,
                'imp_iva' => $iva21, 'imp_iva_21' => $iva21,
                'imp_percepciones_iva' => $percIva, 'imp_percepciones' => $percIva,
                'imp_otros_tributos' => $otrosTr, 'imp_tributos' => $otrosTr,
                'imp_total' => $total,
                'origen' => 'SEGURO',
                'estado' => FacturaCompraService::ESTADO_RECIBIDA,
                'no_tomada' => 0,
                'observaciones' => trim('Seguro '.($data['aseguradora'] ?? '').' · póliza '.($data['poliza'] ?? '').' · ref '.($data['comprobante_ref'] ?? '')),
                'created_by_user_id' => $usuario->id,
            ]);

            $this->audit->logEvento(
                accion: 'SEGURO_PROCESADO',
                modulo: 'compras',
                descripcion: sprintf('Seguro %s cargado como FC #%d (tipo %03d, PV %d Nro %d, total $%.2f)',
                    $data['aseguradora'] ?? '', $factura->id, $tipo, $pv, $numero, $total),
                empresaId: $empresaId,
            );
            return $factura;
        });
    }

    /**
     * Emite el TXT del Libro IVA Digital (CBTE + ALICUOTAS) para los comprobantes
     * indicados, SIN registrar una generación F.8001 (no impacta el numerador).
     * @param  int[]  $facturaIds
     * @return array{cbte:string,alicuotas:string}
     */
    public function emitirTxt(array $facturaIds): array
    {
        $facturas = FacturaCompra::whereIn('id', $facturaIds)->whereNull('deleted_at')->get();
        if ($facturas->isEmpty()) throw new DomainException('SIN_COMPROBANTES');

        $cbte = []; $alic = [];
        foreach ($facturas as $f) {
            $alicuotas = $this->generador->extraerAlicuotas($f);
            $cbte[] = $this->generador->lineaCbte($f, $alicuotas);
            foreach ($alicuotas as $a) {
                $alic[] = $this->generador->lineaAlicuotas($f, $a);
            }
        }
        return [
            'cbte' => implode("\r\n", $cbte) . "\r\n",
            'alicuotas' => implode("\r\n", $alic) . "\r\n",
        ];
    }

    private function extraerTexto(string $pathPdf): string
    {
        $out = [];
        $rc = 0;
        @exec('pdftotext -layout ' . escapeshellarg($pathPdf) . ' - 2>/dev/null', $out, $rc);
        return implode("\n", $out);
    }

    private function upsertProveedor(int $empresaId, string $cuit, string $nombre): int
    {
        $ex = DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
            ->where('tipo', 'Proveedor')->where('cuit', $cuit)->first();
        if ($ex) return (int) $ex->id;

        $ctaDef = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
            ->where('codigo', '2.1.1.01')->value('id');
        return (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $empresaId, 'tipo' => 'Proveedor',
            'codigo' => 'PROV-'.$cuit, 'nombre' => $nombre ?: ('Proveedor '.$cuit),
            'cuit' => $cuit, 'cuenta_contable_default_id' => $ctaDef, 'activo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
