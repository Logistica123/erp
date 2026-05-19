<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Pdf\VentaPdfExtractor;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * ADDENDUM v1.17 — Carga manual de facturas + verificación opcional ARCA.
 *
 *   POST /api/erp/facturas-venta/manual           registrar venta externa
 *   POST /api/erp/facturas-compra/manual          registrar compra fuera del import
 *   POST /api/erp/facturas/{tipo}/{id}/verificar-arca  WSCDC + padrón
 *
 * Reglas (FM-01..FM-10):
 *  - Ventas manual NO emite a ARCA. origen='MANUAL', estado='EMITIDA' (sin CAE
 *    local salvo que el operador lo cargue de la factura externa).
 *  - Compras manual = mismo modelo que el import individual.
 *  - El operador escribe el número (no auto-generado).
 *  - UNIQUE (tipo, PV, numero, cuit). Si duplica → 409.
 *  - Verificación opcional contra arca-gateway (SPEC 04).
 */
class FacturasManualController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HttpClient $http,
        private readonly VentaPdfExtractor $pdfExtractor,
    ) {}

    /**
     * v1.41 — POST /api/erp/facturas-venta/pdf-extract
     * Recibe un PDF (multipart) y devuelve los campos detectados mediante
     * pdftotext + regex. NO inserta ni modifica nada — solo lectura.
     */
    public function extraerDesdePdfVenta(Request $request): JsonResponse
    {
        if (! $this->permiso($request, 'ventas.facturas.cargar_manual')) {
            return $this->sinPermiso();
        }

        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:8192'],
        ]);

        $tmp = $request->file('pdf')->getRealPath();
        $resultado = $this->pdfExtractor->extraer($tmp);

        return response()->json(['ok' => $resultado['ok'], 'data' => $resultado]);
    }

    /**
     * POST /api/erp/facturas-venta/manual
     */
    public function ventaStore(Request $request): JsonResponse
    {
        if (! $this->permiso($request, 'ventas.facturas.cargar_manual')) {
            return $this->sinPermiso();
        }

        $data = $request->validate([
            'tipo_comprobante_id' => ['required', 'integer', 'exists:erp_tipos_comprobante,id'],
            'punto_venta' => ['required', 'integer', 'min:1'],
            'numero' => ['required', 'integer', 'min:1'],
            'fecha_emision' => ['required', 'date'],
            'cliente_auxiliar_id' => ['nullable', 'integer', 'exists:erp_auxiliares,id'],
            'cuit_cliente' => ['nullable', 'string', 'size:11'],
            'razon_social_cliente' => ['nullable', 'string', 'max:200'],
            'condicion_iva_id' => ['nullable', 'integer'],
            'moneda_id' => ['nullable', 'integer'],
            'cotizacion' => ['nullable', 'numeric', 'min:0.0001'],
            'imp_neto_gravado' => ['nullable', 'numeric'],
            'imp_no_gravado' => ['nullable', 'numeric'],
            'imp_exento' => ['nullable', 'numeric'],
            'imp_iva' => ['nullable', 'numeric'],
            // v1.43 — desglose IVA por alícuota (extraído del PDF AFIP).
            'imp_iva_27' => ['nullable', 'numeric'],
            'imp_iva_21' => ['nullable', 'numeric'],
            'imp_iva_10_5' => ['nullable', 'numeric'],
            'imp_iva_5' => ['nullable', 'numeric'],
            'imp_iva_2_5' => ['nullable', 'numeric'],
            'imp_neto_gravado_27' => ['nullable', 'numeric'],
            'imp_neto_gravado_21' => ['nullable', 'numeric'],
            'imp_neto_gravado_10_5' => ['nullable', 'numeric'],
            'imp_neto_gravado_5' => ['nullable', 'numeric'],
            'imp_neto_gravado_2_5' => ['nullable', 'numeric'],
            'imp_total' => ['required', 'numeric'],
            'cae' => ['nullable', 'string', 'max:20'],
            'fecha_vto_cae' => ['nullable', 'date'],
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20'],
            'jurisdiccion_codigo' => ['nullable', 'string', 'size:3'],
            'concepto_afip' => ['nullable', 'integer', 'min:1', 'max:3'],
            // v1.39 — PDF original (AFIP) opcional. Hasta 8MB.
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
        ]);

        // v1.43 — derivar agregados desde el desglose si vino del PDF.
        // Si el cliente mandó imp_iva_21/10_5/etc, sumamos automáticamente
        // imp_iva y imp_neto_gravado (idéntico patrón a v1.25 compras).
        $netosDetalle = [
            (float) ($data['imp_neto_gravado_27'] ?? 0),
            (float) ($data['imp_neto_gravado_21'] ?? 0),
            (float) ($data['imp_neto_gravado_10_5'] ?? 0),
            (float) ($data['imp_neto_gravado_5'] ?? 0),
            (float) ($data['imp_neto_gravado_2_5'] ?? 0),
        ];
        $ivasDetalle = [
            (float) ($data['imp_iva_27'] ?? 0),
            (float) ($data['imp_iva_21'] ?? 0),
            (float) ($data['imp_iva_10_5'] ?? 0),
            (float) ($data['imp_iva_5'] ?? 0),
            (float) ($data['imp_iva_2_5'] ?? 0),
        ];
        $sumaNetos = array_sum($netosDetalle);
        $sumaIvas = array_sum($ivasDetalle);
        if ($sumaNetos > 0) $data['imp_neto_gravado'] = round($sumaNetos, 2);
        if ($sumaIvas > 0)  $data['imp_iva'] = round($sumaIvas, 2);

        $empresaId = $this->empresaId($request);

        // v1.39 — upsert del cliente. Antes (v1.17) si no se encontraba por
        // CUIT devolvíamos 422 y se forzaba al operador a darlo de alta. Con
        // el importer batch de PDFs eso fricciona — siguiendo el patrón ya
        // usado en compraStore (v1.29), si no existe lo creamos al toque con
        // la razón social que vino del form.
        $auxiliarId = $data['cliente_auxiliar_id'] ?? null;
        if (! $auxiliarId && ! empty($data['cuit_cliente'])) {
            $auxiliarId = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tipo', 'Cliente')
                ->where('cuit', $data['cuit_cliente'])
                ->value('id');
        }
        if (! $auxiliarId && ! empty($data['cuit_cliente'])) {
            $cuentaDefaultClienteId = DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)
                ->where('codigo', '1.1.4.01')
                ->value('id');
            $auxiliarId = DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => $empresaId,
                'tipo' => 'Cliente',
                'codigo' => 'CLI-'.$data['cuit_cliente'],
                'nombre' => ($data['razon_social_cliente'] ?? null)
                    ?: ('Cliente '.$data['cuit_cliente']),
                'cuit' => $data['cuit_cliente'],
                'cuenta_contable_default_id' => $cuentaDefaultClienteId,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (! $auxiliarId) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CLIENTE_REQUERIDO',
                    'message' => 'Indicá cliente_auxiliar_id o cuit_cliente.'],
            ], 422);
        }

        // Resolver punto_venta_id desde el numero de PV.
        $pv = DB::table('erp_puntos_venta')
            ->where('empresa_id', $empresaId)
            ->where('numero', $data['punto_venta'])
            ->where('activo', 1)
            ->first(['id']);
        if (! $pv) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'PUNTO_VENTA_INVALIDO',
                    'message' => "Punto de venta {$data['punto_venta']} no existe o no está activo."],
            ], 422);
        }

        // Unicidad (tipo, PV, numero, cliente). Para ventas, el cliente es el
        // auxiliar (no el emisor — el emisor somos nosotros).
        $existe = DB::table('erp_facturas_venta')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $data['tipo_comprobante_id'])
            ->where('punto_venta_id', $pv->id)
            ->where('numero', $data['numero'])
            ->where('auxiliar_id', $auxiliarId)
            ->whereNull('deleted_at')
            ->exists();
        if ($existe) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'FACTURA_DUPLICADA',
                    'message' => 'Ya existe una factura con ese tipo + PV + número para este cliente.'],
            ], 409);
        }

        // CC derivado del cliente.
        $ccId = DB::table('erp_centros_costo')->where('auxiliar_id', $auxiliarId)->value('id');

        // Calcular doc_tipo/doc_nro desde el auxiliar si no vienen.
        $aux = DB::table('erp_auxiliares')->where('id', $auxiliarId)->first(['cuit', 'nombre']);
        $docNro = $data['cuit_cliente'] ?? $aux?->cuit ?? '0';

        $id = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $data['tipo_comprobante_id'],
            'punto_venta_id' => $pv->id,
            'numero' => $data['numero'],
            'cae' => $data['cae'] ?? null,
            'fecha_vto_cae' => $data['fecha_vto_cae'] ?? null,
            'fecha_emision' => $data['fecha_emision'],
            'auxiliar_id' => $auxiliarId,
            'condicion_iva_id' => $data['condicion_iva_id'] ?? 1,
            'doc_tipo_afip' => 80, // CUIT por default
            'doc_nro' => (string) $docNro,
            'moneda_id' => $data['moneda_id'] ?? 1,
            'cotizacion' => $data['cotizacion'] ?? 1,
            'concepto_afip' => $data['concepto_afip'] ?? 2,
            'imp_neto_gravado' => $data['imp_neto_gravado'] ?? 0,
            'imp_no_gravado' => $data['imp_no_gravado'] ?? 0,
            'imp_exento' => $data['imp_exento'] ?? 0,
            'imp_iva' => $data['imp_iva'] ?? 0,
            // v1.43 — desglose por alícuota.
            'imp_iva_27' => $data['imp_iva_27'] ?? 0,
            'imp_iva_21' => $data['imp_iva_21'] ?? 0,
            'imp_iva_10_5' => $data['imp_iva_10_5'] ?? 0,
            'imp_iva_5' => $data['imp_iva_5'] ?? 0,
            'imp_iva_2_5' => $data['imp_iva_2_5'] ?? 0,
            'imp_neto_gravado_27' => $data['imp_neto_gravado_27'] ?? 0,
            'imp_neto_gravado_21' => $data['imp_neto_gravado_21'] ?? 0,
            'imp_neto_gravado_10_5' => $data['imp_neto_gravado_10_5'] ?? 0,
            'imp_neto_gravado_5' => $data['imp_neto_gravado_5'] ?? 0,
            'imp_neto_gravado_2_5' => $data['imp_neto_gravado_2_5'] ?? 0,
            'imp_tributos' => 0,
            'imp_total' => $data['imp_total'],
            'origen' => 'MANUAL',
            'estado' => 'EMITIDA',
            'periodo_trabajado_texto' => $data['periodo_trabajado_texto'] ?? null,
            'jurisdiccion_codigo' => $data['jurisdiccion_codigo'] ?? null,
            'centro_costo_id' => $ccId,
            'verificada_arca' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // v1.39 — Guardar PDF adjunto en disco local privado.
        if ($request->hasFile('pdf')) {
            $file = $request->file('pdf');
            $yyyy = substr($data['fecha_emision'], 0, 4);
            $mm = substr($data['fecha_emision'], 5, 2);
            $relPath = "facturas-venta-pdfs/{$yyyy}/{$mm}/{$id}.pdf";
            Storage::disk('local')->putFileAs(
                "facturas-venta-pdfs/{$yyyy}/{$mm}",
                $file,
                "{$id}.pdf"
            );
            DB::table('erp_facturas_venta')->where('id', $id)->update([
                'pdf_path' => $relPath,
                'updated_at' => now(),
            ]);
        }

        $this->audit->logEvento(
            accion: 'FACTURA_VENTA_MANUAL_REGISTRADA',
            modulo: 'ventas',
            descripcion: sprintf('Venta MANUAL #%d cliente_aux=%d tipo=%d PV=%d nro=%d total=%.2f',
                $id, $auxiliarId, $data['tipo_comprobante_id'], $data['punto_venta'], $data['numero'], $data['imp_total']),
            empresaId: $empresaId,
        );

        return response()->json([
            'ok' => true,
            'data' => ['id' => $id, 'origen' => 'MANUAL', 'estado' => 'EMITIDA'],
        ], 201);
    }

    /**
     * POST /api/erp/facturas-compra/manual
     */
    public function compraStore(Request $request): JsonResponse
    {
        if (! $this->permiso($request, 'compras.facturas.cargar_manual')) {
            return $this->sinPermiso();
        }

        $data = $request->validate([
            'tipo_comprobante_id' => ['required', 'integer', 'exists:erp_tipos_comprobante,id'],
            'punto_venta' => ['required', 'integer', 'min:1'],
            'numero' => ['required', 'integer', 'min:1'],
            'fecha_emision' => ['required', 'date'],
            'fecha_imputacion' => ['required', 'date'],
            'periodo_id' => ['required', 'integer', 'exists:erp_periodos,id'],
            'cuit_emisor' => ['required', 'string', 'size:11'],
            'razon_social_emisor' => ['required', 'string', 'max:200'],
            'auxiliar_id' => ['nullable', 'integer', 'exists:erp_auxiliares,id'],
            'cliente_auxiliar_id' => ['nullable', 'integer', 'exists:erp_auxiliares,id'],
            'centro_costo_id' => ['nullable', 'integer', 'exists:erp_centros_costo,id'],
            'condicion_iva_id' => ['nullable', 'integer'],
            'moneda_id' => ['nullable', 'integer'],
            'imp_neto_gravado' => ['nullable', 'numeric'],
            'imp_no_gravado' => ['nullable', 'numeric'],
            'imp_exento' => ['nullable', 'numeric'],
            'imp_iva' => ['nullable', 'numeric'],
            // v1.25 — desglose por alícuota.
            'imp_neto_gravado_21' => ['nullable', 'numeric'],
            'imp_neto_gravado_10_5' => ['nullable', 'numeric'],
            'imp_neto_gravado_27' => ['nullable', 'numeric'],
            'imp_neto_gravado_2_5' => ['nullable', 'numeric'],
            'imp_neto_gravado_5' => ['nullable', 'numeric'],
            'imp_iva_21' => ['nullable', 'numeric'],
            'imp_iva_10_5' => ['nullable', 'numeric'],
            'imp_iva_27' => ['nullable', 'numeric'],
            'imp_iva_2_5' => ['nullable', 'numeric'],
            'imp_iva_5' => ['nullable', 'numeric'],
            // v1.34 — percepciones detalladas (columnas existen desde v1.24).
            'imp_percepciones_iva' => ['nullable', 'numeric'],
            'imp_percepciones_iibb' => ['nullable', 'numeric'],
            'imp_total' => ['required', 'numeric'],
            'cae' => ['nullable', 'string', 'max:20'],
            'tomado' => ['nullable', 'boolean'],
            'tipo_gasto' => ['nullable', 'string', 'max:80'],
            'observaciones' => ['nullable', 'string'],
            'periodo_trabajado_texto' => ['nullable', 'string', 'max:20'],
            'jurisdiccion_codigo' => ['nullable', 'string', 'size:3'],
        ]);

        $empresaId = $this->empresaId($request);

        // v1.37 — derivar periodo_id real desde fecha_imputacion.
        // Antes el form pasaba periodo_id elegido por el operador, pero podía
        // no coincidir con la fecha_imputacion (caso real: operador elige
        // diciembre como default pero pone fecha en abril). El generador
        // F.8001 filtra por periodo_id → la factura no aparece en el período
        // correcto. El periodo_id debe ser SIEMPRE el que cubre la fecha.
        $periodoReal = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $empresaId)
            ->whereDate('p.fecha_inicio', '<=', $data['fecha_imputacion'])
            ->whereDate('p.fecha_fin', '>=', $data['fecha_imputacion'])
            ->value('p.id');
        if (! $periodoReal) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'PERIODO_NO_ENCONTRADO',
                'message' => "No existe un período fiscal que cubra la fecha de imputación {$data['fecha_imputacion']}.",
            ]], 422);
        }
        $data['periodo_id'] = (int) $periodoReal;

        // v1.25 — si vienen los netos/IVA por alícuota, derivamos los agregados.
        $netos = [
            (float) ($data['imp_neto_gravado_21'] ?? 0),
            (float) ($data['imp_neto_gravado_10_5'] ?? 0),
            (float) ($data['imp_neto_gravado_27'] ?? 0),
            (float) ($data['imp_neto_gravado_2_5'] ?? 0),
            (float) ($data['imp_neto_gravado_5'] ?? 0),
        ];
        $ivas = [
            (float) ($data['imp_iva_21'] ?? 0),
            (float) ($data['imp_iva_10_5'] ?? 0),
            (float) ($data['imp_iva_27'] ?? 0),
            (float) ($data['imp_iva_2_5'] ?? 0),
            (float) ($data['imp_iva_5'] ?? 0),
        ];
        $sumaNetos = array_sum($netos);
        $sumaIvas = array_sum($ivas);
        if ($sumaNetos > 0) $data['imp_neto_gravado'] = round($sumaNetos, 2);
        if ($sumaIvas > 0)  $data['imp_iva'] = round($sumaIvas, 2);

        // Unicidad por (tipo, PV, numero, cuit_emisor).
        $existe = DB::table('erp_facturas_compra')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $data['tipo_comprobante_id'])
            ->where('punto_venta', $data['punto_venta'])
            ->where('numero', $data['numero'])
            ->where('cuit_emisor', $data['cuit_emisor'])
            ->whereNull('deleted_at')
            ->exists();
        if ($existe) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'FACTURA_DUPLICADA',
                    'message' => 'Ya existe una factura con ese tipo + PV + número para este proveedor.'],
            ], 409);
        }

        // Resolver auxiliar del proveedor; si no existe, hacer upsert
        // automático (v1.29) — mismo patrón que el importer del Libro IVA.
        // Antes (v1.17): devolvía 422 PROVEEDOR_NO_ENCONTRADO y forzaba al
        // operador a darlo de alta primero, lo cual fricciona innecesariamente.
        $proveedorAuxId = $data['auxiliar_id'] ?? null;
        if (! $proveedorAuxId) {
            $proveedorAuxId = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tipo', 'Proveedor')
                ->where('cuit', $data['cuit_emisor'])
                ->value('id');
        }
        if (! $proveedorAuxId) {
            // v1.29 — upsert del proveedor con la razón social del form.
            $cuentaDefaultId = DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)
                ->where('codigo', '2.1.1.01')
                ->value('id');
            $proveedorAuxId = DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => $empresaId,
                'tipo' => 'Proveedor',
                'codigo' => 'PROV-'.$data['cuit_emisor'],
                'nombre' => $data['razon_social_emisor'] ?: ('Proveedor '.$data['cuit_emisor']),
                'cuit' => $data['cuit_emisor'],
                'cuenta_contable_default_id' => $cuentaDefaultId,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // CC: si hay cliente_auxiliar_id, deriva de él; si no, usa el manual.
        $ccId = $data['centro_costo_id'] ?? null;
        if (! $ccId && ! empty($data['cliente_auxiliar_id'])) {
            $ccId = DB::table('erp_centros_costo')->where('auxiliar_id', $data['cliente_auxiliar_id'])->value('id');
        }
        if (! $ccId && empty($data['cliente_auxiliar_id'])) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CC_REQUERIDO',
                    'message' => 'Esta factura no tiene cliente asociado. Elegí un Centro de Costos manual (MANT-FLOTA, ALQUILER-OFI, etc.).'],
            ], 422);
        }

        $id = DB::table('erp_facturas_compra')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $data['tipo_comprobante_id'],
            'punto_venta' => $data['punto_venta'],
            'numero' => $data['numero'],
            'cae' => $data['cae'] ?? null,
            'fecha_emision' => $data['fecha_emision'],
            // v1.33 — mismo fix que el v1.21 hizo en el importer:
            // `fecha_recepcion` es NOT NULL sin default. Default a fecha_emision.
            'fecha_recepcion' => $data['fecha_recepcion'] ?? $data['fecha_emision'],
            'fecha_imputacion' => $data['fecha_imputacion'],
            'periodo_id' => $data['periodo_id'],
            'imputacion_diferida' => substr($data['fecha_emision'], 0, 7) !== substr($data['fecha_imputacion'], 0, 7) ? 1 : 0,
            'auxiliar_id' => $proveedorAuxId,
            'cuit_emisor' => $data['cuit_emisor'],
            'razon_social_emisor' => $data['razon_social_emisor'],
            'condicion_iva_id' => $data['condicion_iva_id'] ?? 1,
            'moneda_id' => $data['moneda_id'] ?? 1,
            'cotizacion' => 1.0,
            'imp_neto_gravado' => $data['imp_neto_gravado'] ?? 0,
            'imp_no_gravado' => $data['imp_no_gravado'] ?? 0,
            'imp_exento' => $data['imp_exento'] ?? 0,
            'imp_iva' => $data['imp_iva'] ?? 0,
            // v1.25 — desglose por alícuota
            'imp_neto_gravado_21' => $data['imp_neto_gravado_21'] ?? 0,
            'imp_neto_gravado_10_5' => $data['imp_neto_gravado_10_5'] ?? 0,
            'imp_neto_gravado_27' => $data['imp_neto_gravado_27'] ?? 0,
            'imp_neto_gravado_2_5' => $data['imp_neto_gravado_2_5'] ?? 0,
            'imp_neto_gravado_5' => $data['imp_neto_gravado_5'] ?? 0,
            'imp_iva_21' => $data['imp_iva_21'] ?? 0,
            'imp_iva_10_5' => $data['imp_iva_10_5'] ?? 0,
            'imp_iva_27' => $data['imp_iva_27'] ?? 0,
            'imp_iva_2_5' => $data['imp_iva_2_5'] ?? 0,
            'imp_iva_5' => $data['imp_iva_5'] ?? 0,
            // v1.34 — percepciones (detalladas + agregado para compat).
            'imp_percepciones_iva' => $data['imp_percepciones_iva'] ?? 0,
            'imp_percepciones_iibb' => $data['imp_percepciones_iibb'] ?? 0,
            'imp_percepciones' => (float) ($data['imp_percepciones_iva'] ?? 0)
                                  + (float) ($data['imp_percepciones_iibb'] ?? 0),
            'imp_total' => $data['imp_total'],
            'origen' => 'MANUAL',
            'estado' => 'RECIBIDA',
            'no_tomada' => isset($data['tomado']) && ! $data['tomado'] ? 1 : 0,
            'cliente_auxiliar_id' => $data['cliente_auxiliar_id'] ?? null,
            'centro_costo_id' => $ccId,
            'tipo_gasto' => $data['tipo_gasto'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'periodo_trabajado_texto' => $data['periodo_trabajado_texto'] ?? null,
            'jurisdiccion_codigo' => $data['jurisdiccion_codigo'] ?? null,
            'verificada_arca' => 0,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'FACTURA_COMPRA_MANUAL_REGISTRADA',
            modulo: 'compras',
            descripcion: sprintf('Compra MANUAL #%d cuit=%s tipo=%d PV=%d nro=%d total=%.2f tomado=%s',
                $id, $data['cuit_emisor'], $data['tipo_comprobante_id'], $data['punto_venta'], $data['numero'],
                $data['imp_total'], (isset($data['tomado']) && ! $data['tomado']) ? 'NO' : 'SI'),
            empresaId: $empresaId,
        );

        return response()->json([
            'ok' => true,
            'data' => ['id' => $id, 'origen' => 'MANUAL', 'estado' => 'RECIBIDA'],
        ], 201);
    }

    /**
     * POST /api/erp/facturas/{tipo}/{id}/verificar-arca
     * tipo = 'venta' o 'compra'.
     */
    public function verificarArca(Request $request, string $tipo, int $id): JsonResponse
    {
        if (! $this->permiso($request, 'facturas.verificar_arca')) {
            return $this->sinPermiso();
        }
        if (! in_array($tipo, ['venta', 'compra'], true)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'TIPO_INVALIDO']], 422);
        }
        $tabla = $tipo === 'venta' ? 'erp_facturas_venta' : 'erp_facturas_compra';
        $empresaId = $this->empresaId($request);

        $factura = DB::table($tabla)
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->whereNull('deleted_at')
            ->first();
        if (! $factura) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA']], 404);
        }

        $cuitParaPadron = $tipo === 'compra' ? $factura->cuit_emisor : (string) ($factura->doc_nro ?? '');
        $resultado = [
            'cae_valido' => null,
            'cuit_valido' => null,
            'padron_estado' => null,
            'motivo_rechazo' => null,
        ];

        $cfg = config('services.arca_gateway');
        if (empty($cfg['url'])) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'ARCA_GATEWAY_NO_CONFIGURADO',
                    'message' => 'arca-gateway no está configurado (services.arca_gateway.url).'],
            ], 502);
        }

        try {
            // Padrón A13/A5 — usamos A13 para Ventas (cliente) y A5 para Compras (proveedor).
            // El gateway expone /padron/{cuit} con auto-selección del padrón apropiado.
            $padronResp = $this->http
                ->withHeaders($this->arcaHeaders($cfg))
                ->timeout(10)
                ->get(rtrim($cfg['url'], '/').'/padron/'.urlencode($cuitParaPadron));

            if ($padronResp->successful()) {
                $body = $padronResp->json();
                $resultado['cuit_valido'] = (bool) ($body['encontrado'] ?? $body['ok'] ?? false);
                $resultado['padron_estado'] = $body['estado'] ?? $body['situacion'] ?? null;
            } else {
                $resultado['motivo_rechazo'] = 'PADRON: HTTP '.$padronResp->status();
            }

            // Constatación de CAE — solo si la factura trae CAE cargado.
            if (! empty($factura->cae)) {
                $constatarPayload = [
                    'tipo_cbte' => (int) $factura->tipo_comprobante_id,
                    'pto_vta' => (int) ($factura->punto_venta ?? 0),
                    'cbte_nro' => (int) $factura->numero,
                    'cuit_emisor' => $cuitParaPadron,
                    'fecha_cbte' => $factura->fecha_emision,
                    'imp_total' => (float) $factura->imp_total,
                    'cae' => $factura->cae,
                ];
                $constatarResp = $this->http
                    ->withHeaders($this->arcaHeaders($cfg))
                    ->timeout(15)
                    ->post(rtrim($cfg['url'], '/').'/comp/constatar', $constatarPayload);
                if ($constatarResp->successful()) {
                    $body = $constatarResp->json();
                    $resultado['cae_valido'] = (bool) ($body['cae_valido'] ?? $body['ok'] ?? false);
                    if (! $resultado['cae_valido']) {
                        $resultado['motivo_rechazo'] = $body['motivo'] ?? 'CAE rechazado por WSCDC';
                    }
                } else {
                    $resultado['motivo_rechazo'] = ($resultado['motivo_rechazo'] ?? '').' WSCDC: HTTP '.$constatarResp->status();
                }
            }
        } catch (Throwable $e) {
            Log::error('VERIFICAR_ARCA_FALLO', [
                'tipo' => $tipo, 'factura_id' => $id, 'error' => $e->getMessage(),
            ]);
            $resultado['motivo_rechazo'] = 'Error de comunicación con arca-gateway: '.$e->getMessage();
        }

        $todoOK = ($resultado['cuit_valido'] === true)
            && ($resultado['cae_valido'] === true || empty($factura->cae));

        DB::table($tabla)->where('id', $id)->update([
            'verificada_arca' => $todoOK ? 1 : 0,
            'verificada_arca_at' => now(),
            'verificacion_resultado' => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'FACTURA_VERIFICADA_ARCA',
            modulo: 'general',
            descripcion: sprintf('%s #%d verificada=%s motivo=%s',
                strtoupper($tipo), $id, $todoOK ? 'SI' : 'NO',
                $resultado['motivo_rechazo'] ?? '—'),
            empresaId: $empresaId,
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'verificada' => $todoOK,
                'resultado' => $resultado,
            ],
        ]);
    }

    /**
     * v1.39 — GET /api/erp/facturas-venta/{id}/pdf
     * Devuelve el PDF original (AFIP) adjuntado al cargar la factura.
     */
    public function descargarPdfVenta(Request $request, int $id): Response|StreamedResponse|JsonResponse
    {
        if (! $this->permiso($request, 'ventas.facturas.ver')) {
            return $this->sinPermiso();
        }
        $empresaId = $this->empresaId($request);

        $factura = DB::table('erp_facturas_venta')
            ->where('id', $id)
            ->where('empresa_id', $empresaId)
            ->whereNull('deleted_at')
            ->first(['id', 'pdf_path', 'numero', 'punto_venta_id']);
        if (! $factura) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA']], 404);
        }
        if (! $factura->pdf_path) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'SIN_PDF', 'message' => 'Esta factura no tiene PDF adjunto.'],
            ], 404);
        }
        if (! Storage::disk('local')->exists($factura->pdf_path)) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'PDF_NO_EN_DISCO',
                    'message' => "El path está registrado pero el archivo no existe ({$factura->pdf_path})."],
            ], 410);
        }

        return Storage::disk('local')->response(
            $factura->pdf_path,
            sprintf('factura-venta-%d.pdf', $factura->id),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function empresaId(Request $request): int
    {
        return $request->user()?->erpPerfil?->empresa_id ?? 1;
    }

    private function permiso(Request $request, string $codigo): bool
    {
        return (bool) ($request->user()?->erpPerfil?->tienePermiso($codigo) ?? false);
    }

    private function sinPermiso(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => ['code' => 'SIN_PERMISO'],
        ], 403);
    }

    /** @return array<string,string> */
    private function arcaHeaders(array $cfg): array
    {
        $h = ['Accept' => 'application/json'];
        if (! empty($cfg['api_key'])) $h['X-API-Key'] = $cfg['api_key'];
        if (! empty($cfg['client_id'])) $h['X-Client-ID'] = $cfg['client_id'];
        return $h;
    }
}
