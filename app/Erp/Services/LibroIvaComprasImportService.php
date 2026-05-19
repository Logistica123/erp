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
     * v1.46 — Aliases por columna canónica.
     *
     * AFIP exporta el "Detalle del Libro IVA" con headers que cambiaron entre
     * formatos viejos y nuevos. Algunos casos vistos en prod:
     *   - "Fecha de Emisión" / "Fecha Emisión" / "Fecha"
     *   - "Número de Comprobante" / "Número Desde"
     *   - "Tipo Doc. Vendedor" / "Tipo Doc. Receptor" (recibidos)
     *   - "Importe Total" / "Imp. Total" / "Total"
     *
     * El parser intenta cada alias hasta encontrar uno en el header del archivo.
     * Lo que esté antes en la lista gana si hay múltiples matches.
     */
    private const HEADER_ALIASES = [
        'fecha de emision' => ['fecha de emision', 'fecha emision', 'fecha'],
        'tipo de comprobante' => ['tipo de comprobante', 'tipo comprobante', 'tipo cbte', 'tipo de cbte'],
        'punto de venta' => ['punto de venta', 'pto. de venta', 'pto venta', 'pto. vta', 'pto de vta'],
        'numero de comprobante' => ['numero de comprobante', 'numero comprobante', 'nro. comprobante', 'nro comprobante', 'numero desde', 'nro. desde', 'numero'],
        'tipo doc. vendedor' => ['tipo doc. vendedor', 'tipo doc vendedor', 'tipo doc. receptor', 'tipo doc receptor', 'tipo documento', 'tipo de documento'],
        'nro. doc. vendedor' => ['nro. doc. vendedor', 'nro doc vendedor', 'nro. doc. receptor', 'nro doc receptor', 'cuit vendedor', 'cuit', 'nro. documento'],
        'denominacion vendedor' => ['denominacion vendedor', 'denominacion receptor', 'denominacion', 'razon social', 'razon social vendedor', 'apellido y nombre razon social'],
        'importe total' => ['importe total', 'imp. total', 'imp total', 'total'],
        'numero de comprobante hasta' => ['numero de comprobante hasta', 'numero hasta', 'nro. hasta'],
        // Extras (opcionales).
        'tomado' => ['tomado'],
        'cliente' => ['cliente'],
        'observaciones' => ['observaciones', 'observacion', 'comentario', 'comentarios'],
        'tipo' => ['tipo gasto', 'tipo de gasto', 'tipo'],
        'periodo trabajado' => ['periodo trabajado', 'periodo de trabajo', 'periodo'],
        'jurisdiccion' => ['jurisdiccion', 'cod jurisd', 'codigo jurisdiccion'],
        'op' => ['op', 'orden de pago', 'nro op', 'numero op'],
        'fecha de pago' => ['fecha de pago', 'fecha pago', 'fecha pagado'],
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
        // v1.40 — datos de pago externos (referencia/auditoría, no impactan
        // estado de la factura — eso sigue derivándose de Tesorería).
        'op', 'fecha de pago',
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
        $warnings = []; // v1.22 D-22-3
        $clientesNoMapeados = [];
        $tomadas = 0; $noTomadas = 0; $skipped = 0;
        $proveedoresCreados = 0;

        // v1.22 D-22-2 — atomicidad TODO-O-NADA. Si cualquier fila falla por
        // error real, hacemos rollback total y NO insertamos nada. El upload
        // queda registrado con estado=ERROR_TOTAL para auditoría.
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
                    if (! empty($r['warning'])) {
                        $warnings[] = ['row' => $rowNum, 'motivo' => $r['warning']];
                    }
                } catch (\Throwable $e) {
                    $errores[] = ['row' => $rowNum, 'motivo' => $e->getMessage()];
                }
            }

            if (count($errores) > 0) {
                // Rollback TODO-O-NADA: ninguna factura/asiento queda persistido.
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Persistir resumen del upload (fuera de transaction → autocommit).
        // Si hubo rollback los counts de filas insertadas son 0.
        $estado = count($errores) > 0
            ? LibroIvaComprasImport::ESTADO_ERROR_TOTAL
            : (count($warnings) > 0
                ? LibroIvaComprasImport::ESTADO_OK_CON_WARNINGS
                : LibroIvaComprasImport::ESTADO_COMPLETO);

        $import->update([
            'filas_totales' => count($errores) > 0 ? 0 : ($tomadas + $noTomadas),
            'filas_tomadas' => count($errores) > 0 ? 0 : $tomadas,
            'filas_no_tomadas' => count($errores) > 0 ? 0 : $noTomadas,
            'filas_skipped' => $skipped,
            'filas_error' => count($errores),
            'warnings_count' => count($warnings),
            'errores_detalle' => $errores,
            'warnings_detalle' => $warnings,
            'clientes_no_mapeados' => count($errores) > 0 ? [] : $clientesNoMapeados,
            'proveedores_creados' => count($errores) > 0 ? 0 : $proveedoresCreados,
            'estado' => $estado,
        ]);

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
            'estado' => $estado, // v1.22 — para que el frontend muestre el banner correcto
            'stats' => [
                'totales' => count($errores) > 0 ? 0 : ($tomadas + $noTomadas),
                'tomadas' => count($errores) > 0 ? 0 : $tomadas,
                'no_tomadas' => count($errores) > 0 ? 0 : $noTomadas,
                'skipped' => $skipped,
                'errores' => count($errores),
                'warnings' => count($warnings), // v1.22
                'proveedores_creados' => count($errores) > 0 ? 0 : $proveedoresCreados,
                'clientes_mapeados' => count($errores) > 0 ? 0 : ($tomadas + $noTomadas - count($clientesNoMapeados)),
                'clientes_no_mapeados' => count($errores) > 0 ? 0 : count($clientesNoMapeados),
            ],
            'errores' => $errores,
            'warnings' => $warnings, // v1.22
            'clientes_no_mapeados' => count($errores) > 0 ? [] : $clientesNoMapeados,
        ];
    }

    /** Tomar facturas previamente marcadas como no_tomada en un período X. */
    public function tomarFacturas(
        array $facturaIds,
        int $periodoId,
        User $usuario,
        int $empresaId = 1,
        ?string $periodoTrabajadoTexto = null,
    ): int {
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
            $updateFields = [
                'no_tomada' => 0,
                'fecha_imputacion' => $imp['fecha_imputacion'],
                'periodo_id' => $imp['periodo_id'],
                'imputacion_diferida' => $imp['imputacion_diferida'],
            ];
            // v1.27 — si vino periodo_trabajado_texto en la request, lo seteamos
            // en la misma operación (D-26-10: "tomar + asignar período").
            if ($periodoTrabajadoTexto !== null && $periodoTrabajadoTexto !== '') {
                $updateFields['periodo_trabajado_texto'] = $periodoTrabajadoTexto;
            }
            $f->update($updateFields);
            // Generar asiento si no tiene.
            if (! $f->asiento_id) {
                $this->contabilizador->contabilizarCompra($f->id, $empresaId, $usuario->id);
            }
            $tomadas++;
        }

        // v1.27 — invalidar cache del dropdown de períodos trabajados.
        if ($periodoTrabajadoTexto !== null) {
            \Illuminate\Support\Facades\Cache::forget("facturas_compra.periodos_trabajados.{$empresaId}");
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
        // v1.47 — Laravel guarda los uploads en /tmp con nombres como
        // `phpXXXX.tmp` SIN extensión. La detección anterior por extensión
        // del path tmp siempre fallaba → caía al parser CSV con contenido
        // binario y devolvía garbage como "headers detectados".
        // Solución: detectar el tipo por magic bytes leyendo los primeros
        // bytes del archivo.
        //   - XLSX (zip): "PK\x03\x04"
        //   - XLS (compound): "\xD0\xCF\x11\xE0"
        //   - CSV/TXT: cualquier texto
        $fh = fopen($path, 'rb');
        $magic = $fh ? (string) fread($fh, 8) : '';
        if ($fh) fclose($fh);

        $extPath = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        $esXlsx = str_starts_with($magic, "PK\x03\x04") || $extPath === 'xlsx';
        $esXls = str_starts_with($magic, "\xD0\xCF\x11\xE0") || $extPath === 'xls';

        if ($esXlsx || $esXls) {
            $this->lastEncoding = 'XLSX'; // PhpSpreadsheet entrega strings UTF-8.
            // Forzar el tipo de reader cuando el archivo no tiene extensión
            // (Laravel tmp), si no createReaderForFile() falla.
            $reader = $esXlsx
                ? \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')
                : \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
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
        // 1) Mapa directo: nombre normalizado → idx.
        $byName = [];
        foreach ($row as $idx => $cell) {
            $norm = $this->normalizar((string) $cell);
            if ($norm) $byName[$norm] = $idx;
        }
        // 2) v1.46 — aplicar aliases: para cada canónica que NO esté en byName,
        // probar los aliases. Si encontramos uno, asignamos esa idx a la canónica.
        $map = $byName;
        foreach (self::HEADER_ALIASES as $canonica => $aliases) {
            if (isset($map[$canonica])) continue;
            foreach ($aliases as $alias) {
                $aliasNorm = $this->normalizar($alias);
                if (isset($byName[$aliasNorm])) {
                    $map[$canonica] = $byName[$aliasNorm];
                    break;
                }
            }
        }
        // Guardamos los headers crudos del archivo para diagnóstico en errores.
        $this->lastHeadersDetectados = array_values(array_filter(array_map(
            fn ($c) => $this->normalizar((string) $c), $row,
        )));
        return $map;
    }

    /** v1.46 — guardado solo para mensaje de error si nada matchea. */
    private array $lastHeadersDetectados = [];

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

    /**
     * v1.21 — Parser estricto de fechas en formato AFIP (dd/mm/yyyy).
     *
     * Antes usaba Carbon::parse() que interpreta ambiguamente: con `02/04/2026`
     * podía producir tanto `2026-02-04` (4 feb, día/mes invertidos) como
     * `2026-04-02` (2 abr). Con día > 12 (ej: `13/04/2026`) directamente
     * fallaba el parseo y devolvía null → la fila se rebotaba con
     * FECHA_IMPUTACION_INVALIDA en lugar de un mensaje claro.
     *
     * Ahora: validación de formato regex + createFromFormat estricto +
     * verificación de que los componentes no fueron "casteados" (ej:
     * 31/02/2026 → Carbon castea silencioso a 03/03/2026 — lo detectamos
     * comparando día/mes/año originales contra el Carbon resultante).
     *
     * Devuelve `null` solo si el input está vacío. Si el formato es inválido
     * o la fecha es imposible, lanza DomainException con código prefijado.
     */
    /**
     * v1.22 D-22-1 — fecha_imputacion según relación con el período.
     *
     *   - emisión DENTRO del período  → imputacion = fecha_emision (sin warning)
     *   - emisión ANTERIOR al período → imputacion = inicio del período (sin warning, "factura atrasada")
     *   - emisión POSTERIOR al período → error FECHA_POSTERIOR_AL_PERIODO
     *
     * Nota: el addendum sugería warning para el caso POSTERIOR pero el CHECK
     * constraint `fecha_imputacion >= fecha_emision` lo impide a nivel BD —
     * tocarlo no entra en este sprint. El error es claro y le dice al operador
     * que eligió el período equivocado (o que la factura no corresponde a este
     * archivo).
     *
     * @return array{fecha:string, warning:?string}
     * @throws DomainException si la emisión es posterior al fin del período
     */
    private function calcularFechaImputacion(string $fechaEmision, object $periodo): array
    {
        $inicio = $periodo->fecha_inicio instanceof \DateTimeInterface
            ? $periodo->fecha_inicio->format('Y-m-d')
            : (string) $periodo->fecha_inicio;
        $fin = $periodo->fecha_fin instanceof \DateTimeInterface
            ? $periodo->fecha_fin->format('Y-m-d')
            : (string) $periodo->fecha_fin;

        if ($fechaEmision < $inicio) {
            // Factura atrasada — emitida ANTES del período elegido.
            return ['fecha' => $inicio, 'warning' => null];
        }
        if ($fechaEmision > $fin) {
            $periodoNombre = sprintf('%02d/%d', (int) $periodo->mes, (int) $periodo->anio);
            throw new DomainException(
                "FECHA_POSTERIOR_AL_PERIODO: factura del {$fechaEmision} es posterior al fin del período {$periodoNombre} ({$fin}). "
                . 'Elegí el período correspondiente a la fecha de emisión.'
            );
        }
        // Dentro del período.
        return ['fecha' => $fechaEmision, 'warning' => null];
    }

    private function parsearFecha($v, string $campo = 'fecha'): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '') return null;

        // v1.48 — Si el XLSX viene con el formato visual de la celda y leemos
        // con setReadDataOnly(true), las fechas vienen como serial numbers de
        // Excel (días desde 1899-12-30). Si el valor es un entero plausible
        // como serial de fecha (>= 1 y <= 2958465 = 9999-12-31), lo convertimos.
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            $n = (float) $s;
            if ($n >= 1 && $n <= 2958465) {
                try {
                    $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
                    $s = $dt->format('d/m/Y');
                } catch (\Throwable $e) {
                    // si no se puede convertir, dejamos que el regex de abajo
                    // tire el error de formato con el valor crudo.
                }
            }
        }

        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            throw new DomainException(
                "FECHA_FORMATO_INVALIDO: '{$campo}' = '{$s}' no tiene formato dd/mm/yyyy esperado por AFIP."
            );
        }
        [$_, $d, $mo, $y] = $m;

        try {
            $carbon = Carbon::createFromFormat('!d/m/Y', $s);
        } catch (\Throwable $e) {
            throw new DomainException(
                "FECHA_PARSE_ERROR: '{$campo}' = '{$s}' no se puede parsear: {$e->getMessage()}"
            );
        }

        // createFromFormat acepta 31/02/2026 y lo castea silencioso a 03/03/2026.
        // Verificar que los componentes no cambiaron — rechaza 31/02, 29/02 no
        // bisiesto, 30/04, etc.
        if ($carbon->day !== (int) $d || $carbon->month !== (int) $mo || $carbon->year !== (int) $y) {
            throw new DomainException(
                "FECHA_INVALIDA: '{$campo}' = '{$s}' no es una fecha calendario válida (ej: 31 de febrero, 29 de febrero en año no bisiesto)."
            );
        }

        return $carbon->toDateString();
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

        // v1.46 — diagnóstico claro: distinguir "columna no encontrada" de
        // "columna existe pero valor vacío". Si TODO el archivo no encuentra
        // la columna, el mensaje incluye los headers que SÍ detectó para que
        // el operador pueda comparar.
        if (! isset($headerMap['fecha de emision'])) {
            $disponibles = $this->lastHeadersDetectados;
            $resumen = empty($disponibles)
                ? '(ningún header detectado)'
                : implode(' | ', array_slice($disponibles, 0, 12)).(count($disponibles) > 12 ? ' …' : '');
            throw new DomainException(
                'HEADER_FECHA_EMISION_FALTANTE: no se encontró la columna "Fecha de Emisión" (ni variantes como "Fecha Emisión"). '
                . 'Headers detectados en el archivo: '.$resumen
            );
        }
        $fechaEmision = $this->parsearFecha(
            $this->get($r, $headerMap, 'fecha de emision'),
            'Fecha de Emisión',
        );
        if (! $fechaEmision) {
            throw new DomainException('MISSING_FECHA_EMISION: la columna "Fecha de Emisión" existe pero está vacía en esta fila.');
        }

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

        // v1.24 — desglose por alícuota IVA (5 alícuotas estándar AFIP).
        // El CSV trae el detalle; antes se ignoraba y se usaba sólo Total IVA.
        $impIva21  = $this->parsearFloat($this->get($r, $headerMap, 'importe iva 21%'));
        $impIva10  = $this->parsearFloat($this->get($r, $headerMap, 'importe iva 10,5%'));
        $impIva27  = $this->parsearFloat($this->get($r, $headerMap, 'importe iva 27%'));
        $impIva2   = $this->parsearFloat($this->get($r, $headerMap, 'importe iva 2,5%'));
        $impIva5   = $this->parsearFloat($this->get($r, $headerMap, 'importe iva 5%'));

        // v1.24 — percepciones e impuestos como conceptos separados.
        $impPercIva     = $this->parsearFloat($this->get($r, $headerMap, 'importe de percepciones o pagos a cuenta de iva'));
        $impPercIibb    = $this->parsearFloat($this->get($r, $headerMap, 'importe de percepciones de ingresos brutos'));
        $impPercOtrosNac = $this->parsearFloat($this->get($r, $headerMap, 'importe de per. o pagos a cta. de otros imp. nac.'));
        $impMunicipales = $this->parsearFloat($this->get($r, $headerMap, 'importe de impuestos municipales'));
        $impInternos    = $this->parsearFloat($this->get($r, $headerMap, 'importe de impuestos internos'));
        $impOtrosTrib   = $this->parsearFloat($this->get($r, $headerMap, 'importe otros tributos'));

        // v1.22 D-22-1 — fecha_imputacion inteligente (reemplaza el bug del v1.13
        // que forzaba primer día del período y rebotaba toda fecha_emision > día 1).
        //   - emisión DENTRO del período  → fecha_imputacion = fecha_emision
        //   - emisión ANTERIOR al período → fecha_imputacion = inicio del período (factura atrasada, sin warning)
        //   - emisión POSTERIOR al período → fecha_imputacion = fin del período + warning (no error)
        if ($tomada) {
            $fechaImputacion = $this->calcularFechaImputacion($fechaEmision, $periodo);
            $imp = $this->facturaSvc->resolverImputacion(
                $fechaEmision,
                $fechaImputacion['fecha'],
                $usuario, $empresaId,
            );
            $warningFila = $fechaImputacion['warning']; // null si no aplica
        } else {
            $imp = ['fecha_imputacion' => $fechaEmision, 'periodo_id' => null, 'imputacion_diferida' => 0];
            $warningFila = null;
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
        // v1.40 — OP externa + fecha de pago (referenciales). La OP es texto
        // libre (50 chars). La fecha de pago usa el mismo parser estricto que
        // las demás fechas (dd/mm/yyyy AFIP), pero si está vacía no rompe.
        $opExterna = trim((string) $this->get($r, $headerMap, 'op')) ?: null;
        if ($opExterna !== null && mb_strlen($opExterna) > 50) {
            $opExterna = mb_substr($opExterna, 0, 50);
        }
        // v1.40 — fecha_pago es referencial: si el formato es inválido NO
        // rechazamos la fila (se pierde el dato pero la factura se importa).
        // Decisión explícita del usuario: preferir importar y revisar después
        // a frenar todo por una columna que no impacta el estado contable.
        $fechaPago = null;
        try {
            $fechaPago = $this->parsearFecha(
                $this->get($r, $headerMap, 'fecha de pago'),
                'Fecha de Pago',
            );
        } catch (DomainException $e) {
            $fechaPago = null;
        }
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
            // v1.21 D-21-4: la columna fecha_recepcion es NOT NULL sin DEFAULT.
            // Para imports del Libro IVA asumimos recepción = emisión (FCE u otros
            // casos especiales se cargan por el form manual).
            'fecha_recepcion' => $fechaEmision,
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
            // v1.24 — desglose detallado.
            'imp_iva_21' => $impIva21, 'imp_iva_10_5' => $impIva10, 'imp_iva_27' => $impIva27,
            'imp_iva_2_5' => $impIva2, 'imp_iva_5' => $impIva5,
            'imp_percepciones_iva' => $impPercIva,
            'imp_percepciones_iibb' => $impPercIibb,
            'imp_percepciones_otros_nac' => $impPercOtrosNac,
            'imp_municipales' => $impMunicipales,
            'imp_internos' => $impInternos,
            'imp_otros_tributos' => $impOtrosTrib,
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
            // v1.40 — OP + fecha de pago opcionales.
            'op_externa' => $opExterna,
            'fecha_pago' => $fechaPago,
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
            'warning' => $warningFila ?? null, // v1.22 D-22-3
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
