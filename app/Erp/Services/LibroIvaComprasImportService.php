<?php

namespace App\Erp\Services;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADDENDUM v1.9 — Import enriquecido del Libro IVA Compras.
 *
 * Flujo (validado con LIBER + CSV real de marzo 2026):
 *
 *   1. AFIP "Mis Comprobantes" → CSV crudo con 32 columnas.
 *   2. Contador en Excel agrega 5 columnas:
 *        Tomado | Cliente | Período pagado | Observaciones | Tipo
 *   3. Contador sube el archivo (CSV o XLSX).
 *   4. preview()    detecta header, calcula hash, cuenta filas.
 *   5. confirmar()  procesa cada fila:
 *      - Tomado=SI  → inserta factura + genera asiento contable
 *      - Tomado=NO  → inserta factura con no_tomada=1, sin asiento
 *      - Cliente    → match contra erp_auxiliares (tipo=Cliente) por
 *                     nombre normalizado. Si no matchea, queda NULL y se
 *                     reporta en clientes_no_mapeados.
 *      - Proveedor  → upsert idempotente en erp_auxiliares por CUIT.
 *
 * Idempotencia: archivo con mismo hash en mismo empresa rechaza con 409.
 */
class LibroIvaComprasImportService
{
    /** Encoding del CSV exportado por AFIP. */
    private const CSV_ENCODING = 'ISO-8859-1';

    /** Headers estándar AFIP (case-insensitive, normalizado). */
    private const HEADERS_OBLIGATORIOS = [
        'fecha de emision', 'tipo de comprobante', 'punto de venta',
        'numero de comprobante', 'tipo doc. vendedor', 'nro. doc. vendedor',
        'denominacion vendedor', 'importe total',
    ];

    /**
     * Headers extras del contador (opcionales).
     *
     * v1.14: "periodo pagado" eliminado (era typo del v1.13 — el concepto
     * "Período asignado" ya está cubierto por fecha_imputacion a nivel
     * archivo). Se reemplaza por "periodo trabajado" (período de servicio
     * real) y se agrega "jurisdiccion" (código IIBB AFIP, 901-924).
     */
    private const HEADERS_EXTRAS = [
        'tomado', 'cliente', 'observaciones', 'tipo',
        'periodo trabajado', 'jurisdiccion',
    ];

    public function __construct(
        private readonly ContabilizadorFacturas $contabilizador,
        private readonly FacturaCompraService $facturaSvc,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Paso 1 del wizard: detecta header, hash, cuenta filas. NO inserta nada.
     *
     * @return array{hash:string, archivo_nombre:string, filas_totales:int,
     *               filas_con_tomado_si:int, filas_con_tomado_no:int,
     *               periodo_afip:?string, columnas_extras_detectadas:array,
     *               import_existente:?array}
     */
    public function preview(string $pathTemporal, string $nombreArchivo, int $empresaId = 1): array
    {
        $hash = hash_file('sha256', $pathTemporal);

        $existente = LibroIvaComprasImport::where('empresa_id', $empresaId)
            ->where('archivo_hash', $hash)->first();
        if ($existente) {
            return [
                'hash' => $hash,
                'archivo_nombre' => $nombreArchivo,
                'filas_totales' => 0, 'filas_con_tomado_si' => 0,
                'filas_con_tomado_no' => 0, 'periodo_afip' => null,
                'columnas_extras_detectadas' => [],
                'import_existente' => [
                    'id' => $existente->id,
                    'importado_at' => $existente->importado_at?->toDateTimeString(),
                    'estado' => $existente->estado,
                ],
            ];
        }

        $rows = $this->leerArchivo($pathTemporal);
        if (empty($rows)) {
            throw new DomainException('FORMATO_INVALIDO: archivo vacío o no parseable');
        }

        $headerRaw = array_shift($rows);
        $headerMap = $this->mapearHeader($headerRaw);

        $extras = [];
        foreach (self::HEADERS_EXTRAS as $col) {
            if (isset($headerMap[$col])) $extras[] = $col;
        }

        $tomadasSi = 0;
        $tomadasNo = 0;
        foreach ($rows as $r) {
            if (! $this->filaTieneDatos($r)) continue;
            $tomado = $this->leerTomado($r, $headerMap);
            $tomado ? $tomadasSi++ : $tomadasNo++;
        }

        return [
            'hash' => $hash,
            'archivo_nombre' => $nombreArchivo,
            'encoding_detectado' => $this->lastEncoding, // v1.19
            'filas_totales' => $tomadasSi + $tomadasNo,
            'filas_con_tomado_si' => $tomadasSi,
            'filas_con_tomado_no' => $tomadasNo,
            'periodo_afip' => $this->detectarPeriodoNombre($nombreArchivo),
            'columnas_extras_detectadas' => $extras,
            'import_existente' => null,
        ];
    }

    /**
     * Paso 2 del wizard: procesa el archivo y crea las facturas.
     *
     * @return array{import_id:int, stats:array, errores:array, clientes_no_mapeados:array}
     */
    public function confirmar(
        string $pathTemporal,
        string $nombreArchivo,
        int $periodoImputacionId,
        User $usuario,
        bool $confirmarPeriodoCerrado = false,
        int $empresaId = 1,
    ): array {
        $hash = hash_file('sha256', $pathTemporal);
        if (LibroIvaComprasImport::where('empresa_id', $empresaId)->where('archivo_hash', $hash)->exists()) {
            throw new DomainException('ARCHIVO_DUPLICADO: este archivo ya fue importado.');
        }

        // v1.19 RN-19-3 / D-19-3 — bloqueo total de imputación a período cerrado.
        // Antes había bypass via permiso `compras.imputar_periodo_cerrado` + checkbox
        // de confirmación. Pedido explícito de Sebastián: si está cerrado, NO se imputa.
        // Circuito correcto: Contabilidad → Períodos → Reabrir → Importar → Cerrar.
        $periodo = DB::table('erp_periodos')->where('id', $periodoImputacionId)->first();
        if (! $periodo) {
            throw new DomainException('PERIODO_NO_ENCONTRADO');
        }
        if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            throw new DomainException(
                'PERIODO_CERRADO: el período de imputación está cerrado. Para importar, reabrilo desde Contabilidad → Períodos.'
            );
        }

        $rows = $this->leerArchivo($pathTemporal);
        $headerRaw = array_shift($rows);
        $headerMap = $this->mapearHeader($headerRaw);

        $import = LibroIvaComprasImport::create([
            'empresa_id' => $empresaId,
            'archivo_nombre' => $nombreArchivo,
            'archivo_hash' => $hash,
            // v1.19 — persistimos el encoding detectado para diagnóstico.
            'encoding_detectado' => $this->lastEncoding,
            'periodo_afip' => $this->detectarPeriodoNombre($nombreArchivo),
            'periodo_imputacion_id' => $periodoImputacionId,
            'importado_por' => $usuario->id,
            'importado_at' => now(),
            'estado' => LibroIvaComprasImport::ESTADO_PROCESANDO,
        ]);

        // Persistir el archivo original.
        $rutaStorage = sprintf('erp/libro_iva_compras_import/%d/%d_%s', $empresaId, $import->id, basename($nombreArchivo));
        Storage::disk('local')->put($rutaStorage, file_get_contents($pathTemporal));

        $errores = [];
        $clientesNoMapeados = [];
        $tomadas = 0; $noTomadas = 0; $skipped = 0;
        $proveedoresCreados = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $idx => $rowRaw) {
                $rowNum = $idx + 2; // 1-based + header
                if (! $this->filaTieneDatos($rowRaw)) { $skipped++; continue; }

                try {
                    $r = $this->parsearFila($rowRaw, $headerMap, $empresaId, $periodo, $import->id, $usuario);
                    if ($r['cliente_no_mapeado']) {
                        $clientesNoMapeados[] = ['row' => $rowNum, 'valor' => $r['cliente_no_mapeado']];
                    }
                    if ($r['proveedor_creado']) {
                        $proveedoresCreados++;
                    }
                    if ($r['no_tomada']) {
                        $noTomadas++;
                    } else {
                        $tomadas++;
                    }
                } catch (\Throwable $e) {
                    $errores[] = ['row' => $rowNum, 'motivo' => $e->getMessage()];
                }
            }

            $import->update([
                'filas_totales' => $tomadas + $noTomadas,
                'filas_tomadas' => $tomadas,
                'filas_no_tomadas' => $noTomadas,
                'filas_skipped' => $skipped,
                'filas_error' => count($errores),
                'errores_detalle' => $errores,
                'clientes_no_mapeados' => $clientesNoMapeados,
                'proveedores_creados' => $proveedoresCreados,
                'estado' => count($errores) === 0
                    ? LibroIvaComprasImport::ESTADO_COMPLETO
                    : LibroIvaComprasImport::ESTADO_PARCIAL,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->audit->logEvento(
            accion: 'IMPORT_LIBRO_IVA_COMPRAS',
            modulo: 'compras',
            descripcion: sprintf(
                'Import LIBRO_IVA_COMPRAS #%d — %d tomadas, %d no_tomadas, %d errores',
                $import->id, $tomadas, $noTomadas, count($errores)
            ),
            empresaId: $empresaId,
        );

        return [
            'import_id' => $import->id,
            'stats' => [
                'totales' => $tomadas + $noTomadas,
                'tomadas' => $tomadas,
                'no_tomadas' => $noTomadas,
                'skipped' => $skipped,
                'errores' => count($errores),
                'proveedores_creados' => $proveedoresCreados,
                'clientes_mapeados' => $tomadas + $noTomadas - count($clientesNoMapeados),
                'clientes_no_mapeados' => count($clientesNoMapeados),
            ],
            'errores' => $errores,
            'clientes_no_mapeados' => $clientesNoMapeados,
        ];
    }

    /** Tomar facturas previamente marcadas como no_tomada en un período X. */
    public function tomarFacturas(array $facturaIds, int $periodoId, User $usuario, int $empresaId = 1): int
    {
        $periodo = DB::table('erp_periodos')->where('id', $periodoId)->first();
        if (! $periodo) throw new DomainException('PERIODO_NO_ENCONTRADO');

        $facturas = FacturaCompra::whereIn('id', $facturaIds)
            ->where('empresa_id', $empresaId)
            ->where('no_tomada', 1)
            ->get();

        $tomadas = 0;
        foreach ($facturas as $f) {
            $imp = $this->facturaSvc->resolverImputacion(
                $f->fecha_emision->toDateString(),
                Carbon::create($periodo->anio, $periodo->mes, 1)->toDateString(),
                $usuario, $empresaId
            );
            $f->update([
                'no_tomada' => 0,
                'fecha_imputacion' => $imp['fecha_imputacion'],
                'periodo_id' => $imp['periodo_id'],
                'imputacion_diferida' => $imp['imputacion_diferida'],
            ]);
            // Generar asiento si no tiene.
            if (! $f->asiento_id) {
                $this->contabilizador->contabilizarCompra($f->id, $empresaId, $usuario->id);
            }
            $tomadas++;
        }

        $this->audit->logEvento(
            accion: 'TOMAR_FACTURAS_COMPRA',
            modulo: 'compras',
            descripcion: sprintf('%d facturas reactivadas en período %02d/%d', $tomadas, $periodo->mes, $periodo->anio),
            empresaId: $empresaId,
        );

        return $tomadas;
    }

    // ---------- Helpers privados ----------

    /** Encoding detectado en la última llamada a leerArchivo(). v1.19. */
    private ?string $lastEncoding = null;

    public function getLastEncoding(): ?string
    {
        return $this->lastEncoding;
    }

    private function leerArchivo(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'csv');
        if ($ext === 'xlsx' || $ext === 'xls') {
            $this->lastEncoding = 'XLSX'; // PhpSpreadsheet ya entrega strings UTF-8.
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
            return $sheet->toArray(null, true, false, false);
        }

        // v1.19 RN-19-1: auto-detección de encoding del CSV.
        // AFIP entrega los CSV en ISO-8859-1 (Latin-1) por default — antes el
        // parser asumía siempre ISO-8859-1 hardcodeado, lo que rompía con archivos
        // UTF-8 o Windows-1252.
        $contenido = file_get_contents($path);
        if ($contenido === false) {
            throw new DomainException('FORMATO_INVALIDO: no se pudo abrir el archivo');
        }

        // Descartar BOM UTF-8 si está presente.
        $encoding = null;
        if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
            $contenido = substr($contenido, 3);
            $encoding = 'UTF-8';
        } else {
            $detectado = mb_detect_encoding(
                $contenido,
                ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'],
                true
            );
            $encoding = $detectado !== false ? $detectado : 'ISO-8859-1';
        }
        $this->lastEncoding = $encoding;

        \Illuminate\Support\Facades\Log::info('LibroIvaCompras::leerArchivo', [
            'evento' => 'CSV_ENCODING_DETECTADO',
            'ruta' => basename($path),
            'encoding_detectado' => $encoding,
            'size_bytes' => strlen($contenido),
        ]);

        // Convertir todo a UTF-8 antes del parseo CSV.
        if ($encoding !== 'UTF-8') {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', $encoding);
        }

        // Parsear CSV en memoria (str_getcsv) para no depender de fgetcsv que
        // a veces tiene comportamiento raro con CRLF mixto.
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $contenido) as $linea) {
            if ($linea === '') continue;
            $rows[] = str_getcsv($linea, ';');
        }
        return $rows;
    }

    /** Normaliza header (lowercase, sin acentos, trim) → mapa nombre → idx. */
    private function mapearHeader(array $row): array
    {
        $map = [];
        foreach ($row as $idx => $cell) {
            $norm = $this->normalizar((string) $cell);
            if ($norm) $map[$norm] = $idx;
        }
        return $map;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n',
        ]);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    private function filaTieneDatos(?array $r): bool
    {
        if (! $r) return false;
        foreach ($r as $v) {
            if ($v !== null && trim((string) $v) !== '') return true;
        }
        return false;
    }

    private function leerTomado(array $r, array $headerMap): bool
    {
        if (! isset($headerMap['tomado'])) return true; // default si no hay columna
        $val = trim((string) ($r[$headerMap['tomado']] ?? ''));
        if ($val === '') return true;
        $upper = mb_strtoupper($val);
        return in_array($upper, ['SI', 'S', 'YES', 'Y', '1', 'TRUE'], true);
    }

    private function get(array $r, array $headerMap, string $campo, $default = null)
    {
        return isset($headerMap[$campo]) ? ($r[$headerMap[$campo]] ?? $default) : $default;
    }

    private function detectarPeriodoNombre(string $nombre): ?string
    {
        // Busca "AAAAMM" o "AAAA-MM" o "AAAA_MM" en el nombre.
        if (preg_match('/(\d{4})[\-_]?(\d{2})/', $nombre, $m)) {
            return $m[1].$m[2];
        }
        return null;
    }

    private function parsearFloat($v): float
    {
        if ($v === null || $v === '') return 0.0;
        $s = (string) $v;
        // Format AFIP: 167556,44 (coma decimal). Quitar puntos de miles, cambiar coma por punto.
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }

    private function parsearFecha($v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return Carbon::parse(trim((string) $v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Procesa una fila del CSV. Inserta factura + (si tomada) genera asiento.
     *
     * @return array{no_tomada:bool, cliente_no_mapeado:?string, proveedor_creado:bool}
     */
    private function parsearFila(
        array $r, array $headerMap, int $empresaId, object $periodo, int $importId, User $usuario,
    ): array {
        $tomada = $this->leerTomado($r, $headerMap);

        $fechaEmision = $this->parsearFecha($this->get($r, $headerMap, 'fecha de emision'));
        if (! $fechaEmision) throw new DomainException("fecha de emision inválida");

        $tipoCbteCod = (int) ($this->get($r, $headerMap, 'tipo de comprobante') ?: 0);
        $tipo = DB::table('erp_tipos_comprobante')->where('id', $tipoCbteCod)->first();
        if (! $tipo) throw new DomainException("tipo de comprobante {$tipoCbteCod} no está en catálogo");

        $puntoVenta = (int) ($this->get($r, $headerMap, 'punto de venta') ?: 0);
        $numero = (int) ($this->get($r, $headerMap, 'numero de comprobante') ?: 0);
        $cuit = preg_replace('/[^0-9]/', '', (string) $this->get($r, $headerMap, 'nro. doc. vendedor'));
        $razonSocial = trim((string) $this->get($r, $headerMap, 'denominacion vendedor'));

        // Upsert proveedor (idempotente por CUIT).
        [$proveedorAuxId, $proveedorCreado] = $this->upsertProveedor($empresaId, $cuit, $razonSocial);

        // Match cliente (texto del Excel del contador).
        $clienteTexto = trim((string) $this->get($r, $headerMap, 'cliente'));
        $clienteAuxId = null;
        $clienteNoMapeado = null;
        if ($clienteTexto) {
            $clienteAuxId = $this->matchCliente($empresaId, $clienteTexto);
            if (! $clienteAuxId) $clienteNoMapeado = $clienteTexto;
        }

        // Importes.
        $impTotal = $this->parsearFloat($this->get($r, $headerMap, 'importe total'));
        $impNetoGravado = $this->parsearFloat($this->get($r, $headerMap, 'total neto gravado'));
        $impNoGravado = $this->parsearFloat($this->get($r, $headerMap, 'importe no gravado'));
        $impExento = $this->parsearFloat($this->get($r, $headerMap, 'importe exento'));
        $impIva = $this->parsearFloat($this->get($r, $headerMap, 'total iva'));

        // Imputación: si tomada=NO, fecha_imputacion = fecha_emision (sin asiento).
        if ($tomada) {
            $imp = $this->facturaSvc->resolverImputacion(
                $fechaEmision,
                Carbon::create($periodo->anio, $periodo->mes, 1)->toDateString(),
                $usuario, $empresaId,
            );
        } else {
            $imp = ['fecha_imputacion' => $fechaEmision, 'periodo_id' => null, 'imputacion_diferida' => 0];
        }

        // Período trabajado, jurisdicción y tipo (extras del contador).
        // v1.14: `periodo trabajado` reemplaza al ex `periodo pagado`.
        $periodoTrabajado = $this->normalizarPeriodoTrabajado(
            (string) $this->get($r, $headerMap, 'periodo trabajado')
        );
        $juris = strtoupper(trim((string) $this->get($r, $headerMap, 'jurisdiccion')));
        $juris = preg_match('/^\d{3}$/', $juris) ? $juris : null;
        $tipoGasto = trim((string) $this->get($r, $headerMap, 'tipo')) ?: null;
        $observ = trim((string) $this->get($r, $headerMap, 'observaciones')) ?: null;
        // CC derivado del cliente (auxiliar). Si no hay cliente, queda NULL.
        $centroCostoId = $clienteAuxId
            ? DB::table('erp_centros_costo')->where('auxiliar_id', $clienteAuxId)->value('id')
            : null;

        // v1.18 Sprint U5 (refina v1.17 RN-FM-5): si ya existe una factura
        // MANUAL con misma (tipo, PV, nro, CUIT) → comparar campos clave
        // (fecha_emision, total, neto_gravado, iva) en lugar de solo el total.
        // - MATCH 100% → marcar la MANUAL como `MANUAL_VERIFICADA_IMPORT` +
        //   audit log. NO insertar duplicado. (D-18-6)
        // - DIFF en algún campo → reportar al operador con detalle por campo.
        //   NO acción automática. (D-18-7)
        $manualExistente = DB::table('erp_facturas_compra')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $tipoCbteCod)
            ->where('punto_venta', $puntoVenta)
            ->where('numero', $numero)
            ->where('cuit_emisor', $cuit)
            ->where('origen', 'MANUAL')
            ->whereNull('deleted_at')
            ->first(['id', 'imp_total', 'imp_neto_gravado', 'imp_iva', 'fecha_emision', 'observaciones']);

        if ($manualExistente) {
            // Comparar campos clave (D-18-5): fecha_emision, total, neto, iva.
            $diffs = [];
            $manualFecha = $manualExistente->fecha_emision instanceof \DateTimeInterface
                ? $manualExistente->fecha_emision->format('Y-m-d')
                : (string) $manualExistente->fecha_emision;
            if ($manualFecha !== $fechaEmision) {
                $diffs[] = ['campo' => 'fecha_emision', 'manual' => $manualFecha, 'import' => $fechaEmision];
            }
            if (abs((float) $manualExistente->imp_total - $impTotal) > 0.01) {
                $diffs[] = ['campo' => 'imp_total',
                    'manual' => (float) $manualExistente->imp_total, 'import' => $impTotal];
            }
            if (abs((float) $manualExistente->imp_neto_gravado - $impNetoGravado) > 0.01) {
                $diffs[] = ['campo' => 'imp_neto_gravado',
                    'manual' => (float) $manualExistente->imp_neto_gravado, 'import' => $impNetoGravado];
            }
            if (abs((float) $manualExistente->imp_iva - $impIva) > 0.01) {
                $diffs[] = ['campo' => 'imp_iva',
                    'manual' => (float) $manualExistente->imp_iva, 'import' => $impIva];
            }

            $obsExistente = (string) ($manualExistente->observaciones ?? '');
            if (empty($diffs)) {
                // MATCH 100% — marcar como verificada por import.
                DB::table('erp_facturas_compra')->where('id', $manualExistente->id)->update([
                    'observaciones' => trim($obsExistente.sprintf(
                        "\n[%s] MATCH 100%% con import #%d (todos los campos clave coinciden).",
                        now()->format('Y-m-d H:i'), $importId
                    )),
                    'updated_at' => now(),
                ]);
                throw new \DomainException(sprintf(
                    'CONFLICTO_CON_MANUAL: la factura (%d-%d-%d, CUIT %s) ya existe como MANUAL (#%d). MATCH 100%% — marcada como verificada por import, no se duplica.',
                    $tipoCbteCod, $puntoVenta, $numero, $cuit, $manualExistente->id
                ));
            }

            // DIFF — reportar con detalle por campo.
            $diffsResumen = implode(' · ', array_map(
                fn ($d) => sprintf('%s manual=%s import=%s', $d['campo'], (string) $d['manual'], (string) $d['import']),
                $diffs
            ));
            DB::table('erp_facturas_compra')->where('id', $manualExistente->id)->update([
                'observaciones' => trim($obsExistente.sprintf(
                    "\n[%s] DIFF con import #%d en %d campo(s): %s",
                    now()->format('Y-m-d H:i'), $importId, count($diffs), $diffsResumen
                )),
                'updated_at' => now(),
            ]);
            throw new \DomainException(sprintf(
                'CONFLICTO_CON_MANUAL: factura (%d-%d-%d, CUIT %s) ya existe como MANUAL (#%d). DIFF en %d campo(s): %s — revisar manualmente.',
                $tipoCbteCod, $puntoVenta, $numero, $cuit, $manualExistente->id,
                count($diffs), $diffsResumen
            ));
        }

        $factura = FacturaCompra::create([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $tipoCbteCod,
            'punto_venta' => $puntoVenta, 'numero' => $numero,
            'fecha_emision' => $fechaEmision,
            'fecha_imputacion' => $imp['fecha_imputacion'],
            'periodo_id' => $imp['periodo_id'],
            'imputacion_diferida' => $imp['imputacion_diferida'],
            'auxiliar_id' => $proveedorAuxId,
            'cuit_emisor' => $cuit,
            'razon_social_emisor' => $razonSocial ?: null,
            'condicion_iva_id' => 1, // RI default — se podría inferir del tipo
            'moneda_id' => 1, 'cotizacion' => 1.0,
            'imp_neto_gravado' => $impNetoGravado, 'imp_no_gravado' => $impNoGravado,
            'imp_exento' => $impExento, 'imp_iva' => $impIva,
            'imp_total' => $impTotal,
            'origen' => 'LIBRO_IVA_IMPORT',
            'estado' => $tomada ? FacturaCompraService::ESTADO_RECIBIDA : FacturaCompraService::ESTADO_RECIBIDA,
            'no_tomada' => $tomada ? 0 : 1,
            'cliente_auxiliar_id' => $clienteAuxId,
            'periodo_trabajado_texto' => $periodoTrabajado,
            'jurisdiccion_codigo' => $juris,
            'centro_costo_id' => $centroCostoId,
            'tipo_gasto' => $tipoGasto,
            'observaciones' => $observ,
            'import_id' => $importId,
            'created_by_user_id' => $usuario->id,
        ]);

        // Asiento solo si tomada=SI.
        if ($tomada) {
            $this->contabilizador->contabilizarCompra($factura->id, $empresaId, $usuario->id);
        }

        return [
            'no_tomada' => ! $tomada,
            'cliente_no_mapeado' => $clienteNoMapeado,
            'proveedor_creado' => $proveedorCreado,
        ];
    }

    /**
     * Upsert idempotente del proveedor por CUIT. Si no existe, lo crea con
     * cuenta default 2.1.1.01 (Proveedores Comunes) heredado del v1.10.
     *
     * @return array{0:int, 1:bool}  [auxiliar_id, fue_creado]
     */
    private function upsertProveedor(int $empresaId, string $cuit, string $nombre): array
    {
        $existente = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'Proveedor')
            ->where('cuit', $cuit)
            ->first();
        if ($existente) {
            return [(int) $existente->id, false];
        }

        $cuentaDefaultId = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)
            ->where('codigo', '2.1.1.01')
            ->value('id');

        $id = DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo' => 'Proveedor',
            'codigo' => 'PROV-'.$cuit,
            'nombre' => $nombre ?: ('Proveedor '.$cuit),
            'cuit' => $cuit,
            'cuenta_contable_default_id' => $cuentaDefaultId,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [(int) $id, true];
    }

    /** Match cliente por nombre normalizado vs erp_auxiliares tipo=Cliente. */
    private function matchCliente(int $empresaId, string $texto): ?int
    {
        $norm = $this->normalizar($texto);
        if ($norm === '') return null;

        $candidatos = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'Cliente')
            ->where('activo', 1)
            ->select('id', 'nombre')
            ->get();

        $matches = [];
        foreach ($candidatos as $c) {
            $nombreNorm = $this->normalizar((string) $c->nombre);
            if ($nombreNorm === $norm) return (int) $c->id; // match exacto
            if (str_starts_with($nombreNorm, $norm) || str_starts_with($norm, $nombreNorm)) {
                $matches[] = (int) $c->id;
            }
        }
        // Si hay 1 match aproximado único, lo retornamos. Si hay >1 → ambiguo, NULL.
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function normalizarPeriodo(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') return null;
        // YYYY-MM, YYYY/MM, MM/YYYY, MM-YYYY
        if (preg_match('/^(\d{4})[\-\/](\d{1,2})$/', $texto, $m)) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^(\d{1,2})[\-\/](\d{4})$/', $texto, $m)) {
            return sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }
        return $texto; // guardar crudo si no parsea (no falla)
    }

    /**
     * v1.14: período trabajado acepta YYYY-MM (mensual) o YYYY-MM-Q1/Q2 (quincenal).
     * Si no parsea, guarda crudo (igual que normalizarPeriodo).
     */
    private function normalizarPeriodoTrabajado(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') return null;
        if (preg_match('/^(\d{4})[\-\/](\d{1,2})[\-\/]?(Q[12])?$/i', $texto, $m)) {
            $base = sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
            return isset($m[3]) && $m[3] !== '' ? $base.'-'.strtoupper($m[3]) : $base;
        }
        return $this->normalizarPeriodo($texto);
    }
}
