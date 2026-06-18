<?php

namespace App\Erp\Services\Seguros;

use App\Erp\Services\GeneradorF8001Service;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento de Seguro — módulo AUTÓNOMO y separado del resto del ERP.
 *
 * Carga PDFs de pólizas en su propia tabla (erp_seguros_comprobantes) SIN
 * impactar ningún otro módulo (no crea facturas de compra, no toca saldos ni
 * la numeración del ERP). Detecta duplicados por hash del PDF. Luego permite
 * emitir el TXT del Libro IVA Digital (comprobante + alícuotas) de estos
 * comprobantes para importarlos a AFIP.
 */
class ProcesamientoSeguroService
{
    public function __construct(
        private readonly ParserSeguroFactory $factory,
        private readonly GeneradorF8001Service $generador,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Extrae el detalle del PDF + calcula el hash para dedup. No escribe nada.
     * @return array<string,mixed>
     */
    public function analizar(string $pathPdf, int $empresaId = 1): array
    {
        $texto = $this->extraerTexto($pathPdf);
        if (trim($texto) === '') {
            throw new DomainException('PDF_SIN_TEXTO: el PDF parece escaneado (sin texto). Por ahora se soportan PDFs con texto.');
        }
        $parser = $this->factory->resolver($texto);
        $data = $parser->parse($texto);

        $hash = hash_file('sha256', $pathPdf);
        $data['contenido_hash'] = $hash;
        $ya = DB::table('erp_seguros_comprobantes')
            ->where('empresa_id', $empresaId)->where('contenido_hash', $hash)->first();
        $data['duplicado'] = (bool) $ya;
        $data['duplicado_id'] = $ya->id ?? null;

        return $data;
    }

    /**
     * Guarda el comprobante de seguro en el módulo (tabla propia). Rechaza si el
     * mismo PDF (hash) ya fue cargado.
     * @return array<string,mixed>
     */
    public function cargar(array $data, User $usuario, int $empresaId = 1): array
    {
        foreach (['cuit_aseguradora', 'fecha_emision', 'tipo_comprobante_id', 'contenido_hash', 'imp_total'] as $req) {
            if (! isset($data[$req]) || $data[$req] === '' || $data[$req] === null) {
                throw new DomainException("CAMPO_REQUERIDO: falta {$req}");
            }
        }
        $hash = (string) $data['contenido_hash'];
        $dup = DB::table('erp_seguros_comprobantes')
            ->where('empresa_id', $empresaId)->where('contenido_hash', $hash)->first();
        if ($dup) {
            throw new DomainException("COMPROBANTE_DUPLICADO: este PDF ya fue cargado (comprobante de seguro #{$dup->id}).");
        }

        $id = DB::table('erp_seguros_comprobantes')->insertGetId([
            'empresa_id' => $empresaId,
            'aseguradora' => $data['aseguradora'] ?? null,
            'cuit_aseguradora' => preg_replace('/\D/', '', (string) $data['cuit_aseguradora']),
            'fecha_emision' => $data['fecha_emision'],
            'fecha_imputacion' => $data['fecha_imputacion'] ?? null,
            'poliza' => $data['poliza'] ?? null,
            'comprobante_ref' => $data['comprobante_ref'] ?? null,
            'tipo_comprobante' => (int) $data['tipo_comprobante_id'],
            'punto_venta' => (int) ($data['punto_venta'] ?? 0),
            'numero' => (int) ($data['numero'] ?? 0),
            'imp_neto_gravado_21' => round((float) ($data['imp_neto_gravado_21'] ?? 0), 2),
            'imp_iva_21' => round((float) ($data['imp_iva_21'] ?? 0), 2),
            'imp_percepciones_iva' => round((float) ($data['imp_percepciones_iva'] ?? 0), 2),
            'imp_otros_tributos' => round((float) ($data['imp_otros_tributos'] ?? 0), 2),
            'imp_total' => round((float) $data['imp_total'], 2),
            'contenido_hash' => $hash,
            'nombre_archivo' => $data['nombre_archivo'] ?? null,
            'crudos' => isset($data['crudos']) ? json_encode($data['crudos']) : null,
            'created_by_user_id' => $usuario->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'SEGURO_COMPROBANTE_CARGADO', modulo: 'seguros',
            descripcion: sprintf('Seguro %s cargado #%d (tipo %03d, total $%.2f) — módulo autónomo',
                $data['aseguradora'] ?? '', $id, (int) $data['tipo_comprobante_id'], (float) $data['imp_total']),
            empresaId: $empresaId,
        );

        return (array) DB::table('erp_seguros_comprobantes')->find($id);
    }

    /**
     * Carga varios comprobantes en lote. Cada ítem es el resultado revisado de
     * analizar() + punto_venta/numero. Devuelve un resultado por ítem.
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public function cargarLote(array $items, User $usuario, int $empresaId = 1): array
    {
        $res = [];
        foreach ($items as $i => $item) {
            $nombre = $item['nombre_archivo'] ?? ('item '.($i + 1));
            try {
                $row = $this->cargar($item, $usuario, $empresaId);
                $res[] = ['ok' => true, 'id' => $row['id'], 'nombre_archivo' => $nombre, 'aseguradora' => $row['aseguradora'] ?? null];
            } catch (DomainException $e) {
                [$code] = explode(':', $e->getMessage(), 2);
                $res[] = ['ok' => false, 'nombre_archivo' => $nombre, 'error' => $code, 'mensaje' => $e->getMessage()];
            }
        }
        return $res;
    }

    /** @return array<int,object> */
    public function listar(int $empresaId = 1): array
    {
        return DB::table('erp_seguros_comprobantes')
            ->where('empresa_id', $empresaId)->orderByDesc('id')->get()->all();
    }

    public function eliminar(int $id, User $usuario, int $empresaId = 1): void
    {
        $row = DB::table('erp_seguros_comprobantes')->where('empresa_id', $empresaId)->where('id', $id)->first();
        if (! $row) throw new DomainException('NO_ENCONTRADO');
        DB::table('erp_seguros_comprobantes')->where('id', $id)->delete();
        $this->audit->logEvento('SEGURO_COMPROBANTE_BORRADO', 'seguros', "Seguro #{$id} borrado del módulo", $empresaId);
    }

    /**
     * Emite el TXT del Libro IVA Digital (CBTE + ALICUOTAS) para los comprobantes
     * de seguro indicados (o todos), reusando el formato del generador F.8001 con
     * objetos propios — sin tocar erp_facturas_compra.
     * @param  int[]  $ids  vacío = todos
     * @return array{cbte:string,alicuotas:string,cant:int}
     */
    public function emitirTxt(array $ids = [], int $empresaId = 1): array
    {
        $q = DB::table('erp_seguros_comprobantes')->where('empresa_id', $empresaId);
        if (! empty($ids)) $q->whereIn('id', $ids);
        $rows = $q->orderBy('id')->get();
        if ($rows->isEmpty()) throw new DomainException('SIN_COMPROBANTES');

        $cbte = []; $alic = [];
        foreach ($rows as $r) {
            $obj = $this->aObjetoFactura($r);
            $alicuotas = $this->generador->extraerAlicuotas($obj);
            $cbte[] = $this->generador->lineaCbte($obj, $alicuotas);
            foreach ($alicuotas as $a) {
                $alic[] = $this->generador->lineaAlicuotas($obj, $a);
            }
        }
        return [
            'cbte' => implode("\r\n", $cbte) . "\r\n",
            'alicuotas' => implode("\r\n", $alic) . "\r\n",
            'cant' => $rows->count(),
        ];
    }

    /**
     * Construye un objeto con la forma que espera GeneradorF8001Service, SIN ser
     * una factura real. id=0 garantiza que extraerAlicuotas no matchee filas de
     * erp_factura_compra_iva y use el desglose imp_iva_21/imp_neto_gravado_21.
     */
    private function aObjetoFactura(object $r): object
    {
        return (object) [
            'id' => 0,
            'fecha_emision' => $r->fecha_emision,
            'tipo_comprobante_id' => (int) $r->tipo_comprobante, // generador lo usa como código AFIP
            'punto_venta' => (int) $r->punto_venta,
            'numero' => (int) $r->numero,
            'cuit_emisor' => $r->cuit_aseguradora,
            'razon_social_emisor' => $r->aseguradora,
            'cotizacion' => 1.0,
            'imp_total' => (float) $r->imp_total,
            'imp_no_gravado' => 0.0, 'imp_exento' => 0.0,
            'imp_neto_gravado' => (float) $r->imp_neto_gravado_21,
            'imp_neto_gravado_21' => (float) $r->imp_neto_gravado_21,
            'imp_iva' => (float) $r->imp_iva_21,
            'imp_iva_21' => (float) $r->imp_iva_21,
            'imp_percepciones_iva' => (float) $r->imp_percepciones_iva,
            'imp_percepciones_otros_nac' => 0.0, 'imp_percepciones_iibb' => 0.0,
            'imp_municipales' => 0.0, 'imp_internos' => 0.0,
            'imp_otros_tributos' => (float) $r->imp_otros_tributos,
            'imp_tributos' => (float) $r->imp_otros_tributos,
        ];
    }

    private function extraerTexto(string $pathPdf): string
    {
        $out = []; $rc = 0;
        @exec('pdftotext -layout ' . escapeshellarg($pathPdf) . ' - 2>/dev/null', $out, $rc);
        return implode("\n", $out);
    }
}
