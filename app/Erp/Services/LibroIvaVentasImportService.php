<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Models\VentasCompras\LibroIvaVentasImport;
use App\Erp\Services\Integracion\ContabilizadorFacturas;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v1.45 — Import del Libro IVA Ventas (espejo de v1.9 Compras adaptado).
 *
 * Flujo:
 *   1. AFIP "Mis Comprobantes > Emitidos" → CSV con columnas estándar.
 *   2. Contador puede sumar 3 columnas opcionales:
 *        COD JURISD | COMENTARIO | PERIODO TRABAJADO
 *   3. Contador sube CSV o XLSX.
 *   4. preview() — detecta header, hash, cuenta filas.
 *   5. confirmar() — inserta facturas + genera asiento por cada una.
 *      - Cliente: upsert por CUIT (mismo patrón v1.39 carga manual).
 *      - Asiento: `contabilizarVenta($facturaId)` (Integracion\Contabilizador).
 *      - Atomicidad TODO-O-NADA: si cualquier fila falla, rollback total.
 *
 * Idempotencia: archivo con mismo SHA256 y empresa_id ya importado rebota 409.
 */
class LibroIvaVentasImportService
{
    /** Headers obligatorios (case-insensitive normalizado) del CSV AFIP. */
    private const HEADERS_OBLIGATORIOS = [
        'fecha de emision', 'tipo de comprobante', 'punto de venta',
        'numero desde', 'tipo doc. receptor', 'nro. doc. receptor',
        'denominacion receptor', 'imp. total',
    ];

    /** Headers extras opcionales del contador. */
    private const HEADERS_EXTRAS = [
        'cod jurisd', 'comentario', 'periodo trabajado',
    ];

    /** Encoding detectado de la última lectura. */
    private ?string $lastEncoding = null;

    public function __construct(
        private readonly ContabilizadorFacturas $contabilizador,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Paso 1 del wizard.
     */
    public function preview(string $pathTemporal, string $nombreArchivo, int $empresaId = 1): array
    {
        $hash = hash_file('sha256', $pathTemporal);

        $existente = LibroIvaVentasImport::where('empresa_id', $empresaId)
            ->where('archivo_hash', $hash)->first();
        if ($existente) {
            return [
                'hash' => $hash,
                'archivo_nombre' => $nombreArchivo,
                'filas_totales' => 0,
                'periodo_afip' => null,
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

        // Validar columnas obligatorias.
        $faltantes = [];
        foreach (self::HEADERS_OBLIGATORIOS as $col) {
            if (! isset($headerMap[$col])) $faltantes[] = $col;
        }
        if (! empty($faltantes)) {
            throw new DomainException(
                'HEADERS_FALTANTES: el archivo no tiene las columnas obligatorias de AFIP: '
                . implode(', ', $faltantes)
            );
        }

        $filas = 0;
        foreach ($rows as $r) {
            if ($this->filaTieneDatos($r)) $filas++;
        }

        return [
            'hash' => $hash,
            'archivo_nombre' => $nombreArchivo,
            'encoding_detectado' => $this->lastEncoding,
            'filas_totales' => $filas,
            'periodo_afip' => $this->detectarPeriodoNombre($nombreArchivo),
            'columnas_extras_detectadas' => $extras,
            'import_existente' => null,
        ];
    }

    /**
     * Paso 2: procesa el archivo y crea las facturas + asientos.
     */
    public function confirmar(
        string $pathTemporal,
        string $nombreArchivo,
        int $periodoImputacionId,
        User $usuario,
        int $empresaId = 1,
    ): array {
        $hash = hash_file('sha256', $pathTemporal);
        if (LibroIvaVentasImport::where('empresa_id', $empresaId)->where('archivo_hash', $hash)->exists()) {
            throw new DomainException('ARCHIVO_DUPLICADO: este archivo ya fue importado.');
        }

        $periodo = DB::table('erp_periodos')->where('id', $periodoImputacionId)->first();
        if (! $periodo) {
            throw new DomainException('PERIODO_NO_ENCONTRADO');
        }
        if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            throw new DomainException(
                'PERIODO_CERRADO: el período está cerrado. Reabrilo desde Contabilidad → Períodos.'
            );
        }

        $rows = $this->leerArchivo($pathTemporal);
        $headerRaw = array_shift($rows);
        $headerMap = $this->mapearHeader($headerRaw);

        $import = LibroIvaVentasImport::create([
            'empresa_id' => $empresaId,
            'archivo_nombre' => $nombreArchivo,
            'archivo_hash' => $hash,
            'encoding_detectado' => $this->lastEncoding,
            'periodo_afip' => $this->detectarPeriodoNombre($nombreArchivo),
            'periodo_imputacion_id' => $periodoImputacionId,
            'importado_por' => $usuario->id,
            'importado_at' => now(),
            'estado' => LibroIvaVentasImport::ESTADO_PROCESANDO,
        ]);

        $rutaStorage = sprintf('erp/libro_iva_ventas_import/%d/%d_%s',
            $empresaId, $import->id, basename($nombreArchivo));
        Storage::disk('local')->put($rutaStorage, file_get_contents($pathTemporal));

        $errores = [];
        $warnings = [];
        $clientesNoMapeados = [];
        $ok = 0; $skipped = 0; $clientesCreados = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $idx => $rowRaw) {
                $rowNum = $idx + 2;
                if (! $this->filaTieneDatos($rowRaw)) { $skipped++; continue; }

                try {
                    $r = $this->parsearFila($rowRaw, $headerMap, $empresaId, $periodo, $import->id, $usuario);
                    if ($r['cliente_no_mapeado']) {
                        $clientesNoMapeados[] = ['row' => $rowNum, 'valor' => $r['cliente_no_mapeado']];
                    }
                    if ($r['cliente_creado']) $clientesCreados++;
                    if (! empty($r['warning'])) {
                        $warnings[] = ['row' => $rowNum, 'motivo' => $r['warning']];
                    }
                    $ok++;
                } catch (\Throwable $e) {
                    $errores[] = ['row' => $rowNum, 'motivo' => $e->getMessage()];
                }
            }

            if (count($errores) > 0) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $estado = count($errores) > 0
            ? LibroIvaVentasImport::ESTADO_ERROR_TOTAL
            : (count($warnings) > 0
                ? LibroIvaVentasImport::ESTADO_OK_CON_WARNINGS
                : LibroIvaVentasImport::ESTADO_COMPLETO);

        $import->update([
            'filas_totales' => count($errores) > 0 ? 0 : $ok,
            'filas_ok' => count($errores) > 0 ? 0 : $ok,
            'filas_skipped' => $skipped,
            'filas_error' => count($errores),
            'warnings_count' => count($warnings),
            'errores_detalle' => $errores,
            'warnings_detalle' => $warnings,
            'clientes_no_mapeados' => count($errores) > 0 ? [] : $clientesNoMapeados,
            'clientes_creados' => count($errores) > 0 ? 0 : $clientesCreados,
            'estado' => $estado,
        ]);

        $this->audit->logEvento(
            accion: 'IMPORT_LIBRO_IVA_VENTAS',
            modulo: 'ventas',
            descripcion: sprintf(
                'Import LIBRO_IVA_VENTAS #%d — %d ok, %d errores, %d clientes creados',
                $import->id, $ok, count($errores), $clientesCreados
            ),
            empresaId: $empresaId,
        );

        return [
            'import_id' => $import->id,
            'estado' => $estado,
            'stats' => [
                'totales' => count($errores) > 0 ? 0 : $ok,
                'skipped' => $skipped,
                'errores' => count($errores),
                'warnings' => count($warnings),
                'clientes_creados' => count($errores) > 0 ? 0 : $clientesCreados,
                'clientes_no_mapeados' => count($errores) > 0 ? 0 : count($clientesNoMapeados),
            ],
            'errores' => $errores,
            'warnings' => $warnings,
            'clientes_no_mapeados' => count($errores) > 0 ? [] : $clientesNoMapeados,
        ];
    }

    /**
     * Procesa una fila del CSV. Inserta factura + genera asiento.
     */
    private function parsearFila(
        array $r, array $headerMap, int $empresaId, object $periodo, int $importId, User $usuario,
    ): array {
        $fechaEmision = $this->parsearFecha(
            $this->get($r, $headerMap, 'fecha de emision'),
            'Fecha de Emisión',
        );
        if (! $fechaEmision) {
            throw new DomainException('FECHA_EMISION_FALTANTE');
        }

        $tipoCbteCod = (int) $this->parsearInt($this->get($r, $headerMap, 'tipo de comprobante'));
        if (! $tipoCbteCod) {
            throw new DomainException('TIPO_COMPROBANTE_FALTANTE');
        }
        // Verificar que el tipo de comprobante existe en la tabla.
        $tipoCbte = DB::table('erp_tipos_comprobante')->where('id', $tipoCbteCod)
            ->first(['id', 'clase']);
        if (! $tipoCbte) {
            throw new DomainException("TIPO_COMPROBANTE_DESCONOCIDO: cod={$tipoCbteCod}");
        }

        $puntoVentaNum = (int) $this->parsearInt($this->get($r, $headerMap, 'punto de venta'));
        if (! $puntoVentaNum) {
            throw new DomainException('PUNTO_VENTA_FALTANTE');
        }
        $puntoVenta = DB::table('erp_puntos_venta')
            ->where('empresa_id', $empresaId)
            ->where('numero', $puntoVentaNum)
            ->first(['id']);
        if (! $puntoVenta) {
            throw new DomainException("PUNTO_VENTA_INEXISTENTE: nro={$puntoVentaNum}. Crealo desde Configuración → Puntos de venta.");
        }

        $numero = (int) $this->parsearInt($this->get($r, $headerMap, 'numero desde'));
        if (! $numero) {
            throw new DomainException('NUMERO_FALTANTE');
        }

        // Receptor — doc tipo + nro. AFIP usa 80 = CUIT, 86 = CUIL, 96 = DNI, 99 = CF.
        $docTipo = (int) $this->parsearInt($this->get($r, $headerMap, 'tipo doc. receptor'));
        if (! $docTipo) $docTipo = 80;
        $docNro = trim((string) $this->get($r, $headerMap, 'nro. doc. receptor'));
        $razonSocial = trim((string) $this->get($r, $headerMap, 'denominacion receptor'));

        // Importes — el CSV puede traer aggregate o desglose por alícuota.
        $impTotal = $this->parsearFloat($this->get($r, $headerMap, 'imp. total'));
        $impNeto = $this->parsearFloat($this->get($r, $headerMap, 'imp. neto gravado'));
        $impNoGravado = $this->parsearFloat($this->get($r, $headerMap, 'imp. neto no gravado'));
        $impExento = $this->parsearFloat($this->get($r, $headerMap, 'imp. op. exentas'));
        $otrosTributos = $this->parsearFloat($this->get($r, $headerMap, 'otros tributos'));
        $impIvaAgg = $this->parsearFloat($this->get($r, $headerMap, 'iva'));

        // Desglose por alícuota (AFIP detalle).
        $detalle = [
            '27'  => ['neto' => $this->parsearFloat($this->get($r, $headerMap, 'imp. neto c/iva 27%')), 'factor' => 0.27],
            '21'  => ['neto' => $this->parsearFloat($this->get($r, $headerMap, 'imp. neto c/iva 21%')), 'factor' => 0.21],
            '10_5'=> ['neto' => $this->parsearFloat($this->get($r, $headerMap, 'imp. neto c/iva 10,5%')), 'factor' => 0.105],
            '5'   => ['neto' => $this->parsearFloat($this->get($r, $headerMap, 'imp. neto c/iva 5%')), 'factor' => 0.05],
            '2_5' => ['neto' => $this->parsearFloat($this->get($r, $headerMap, 'imp. neto c/iva 2,5%')), 'factor' => 0.025],
        ];
        // Si no encontramos con esos labels exactos, buscamos variantes con punto
        // en lugar de coma decimal ("10.5%").
        foreach ($detalle as $k => &$row) {
            if ($row['neto'] == 0) {
                $alt = $this->parsearFloat($this->get($r, $headerMap,
                    'imp. neto c/iva '.str_replace('_', '.', $k).'%'));
                if ($alt > 0) $row['neto'] = $alt;
            }
        }
        unset($row);

        // Derivar IVA por alícuota a partir del neto detallado.
        $impIva27 = round($detalle['27']['neto'] * 0.27, 2);
        $impIva21 = round($detalle['21']['neto'] * 0.21, 2);
        $impIva10_5 = round($detalle['10_5']['neto'] * 0.105, 2);
        $impIva5 = round($detalle['5']['neto'] * 0.05, 2);
        $impIva2_5 = round($detalle['2_5']['neto'] * 0.025, 2);
        $impIvaSuma = $impIva27 + $impIva21 + $impIva10_5 + $impIva5 + $impIva2_5;
        $netoDetalleSuma = $detalle['27']['neto'] + $detalle['21']['neto']
            + $detalle['10_5']['neto'] + $detalle['5']['neto'] + $detalle['2_5']['neto'];

        // Si el desglose detallado suma 0 pero hay aggregate, asumimos 21% (caso típico).
        if ($netoDetalleSuma < 0.01 && $impNeto > 0.01) {
            $detalle['21']['neto'] = $impNeto;
            $impIva21 = $impIvaAgg > 0.01 ? round($impIvaAgg, 2) : round($impNeto * 0.21, 2);
            $impIvaSuma = $impIva21;
            $netoDetalleSuma = $impNeto;
        }

        // Aggregate final.
        $impNetoFinal = $netoDetalleSuma > 0.01 ? round($netoDetalleSuma, 2) : round($impNeto, 2);
        $impIvaFinal = $impIvaSuma > 0.01 ? round($impIvaSuma, 2) : round($impIvaAgg, 2);

        // Fecha imputación.
        $imp = $this->calcularFechaImputacion($fechaEmision, $periodo);

        // Extras opcionales.
        $juris = strtoupper(trim((string) $this->get($r, $headerMap, 'cod jurisd')));
        $juris = preg_match('/^\d{3}$/', $juris) ? $juris : null;
        $observ = trim((string) $this->get($r, $headerMap, 'comentario')) ?: null;
        $periodoTrabajado = $this->normalizarPeriodoTrabajado(
            (string) $this->get($r, $headerMap, 'periodo trabajado')
        );

        // Upsert cliente por CUIT (mismo patrón v1.39).
        $clienteAuxId = null;
        $clienteCreado = false;
        $clienteNoMapeado = null;
        if ($docTipo === 80 && preg_match('/^\d{11}$/', $docNro)) {
            $clienteAuxId = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tipo', 'Cliente')
                ->where('cuit', $docNro)
                ->value('id');
            if (! $clienteAuxId && $razonSocial !== '') {
                $cuentaDefaultId = DB::table('erp_cuentas_contables')
                    ->where('empresa_id', $empresaId)
                    ->where('codigo', '1.1.4.01')
                    ->value('id');
                $clienteAuxId = DB::table('erp_auxiliares')->insertGetId([
                    'empresa_id' => $empresaId,
                    'tipo' => 'Cliente',
                    'codigo' => 'CLI-'.$docNro,
                    'nombre' => $razonSocial,
                    'cuit' => $docNro,
                    'cuenta_contable_default_id' => $cuentaDefaultId,
                    'activo' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $clienteCreado = true;
            } elseif (! $clienteAuxId) {
                $clienteNoMapeado = "{$docNro} (sin razón social)";
            }
        } elseif ($docTipo === 99) {
            // Consumidor Final — no se mapea a auxiliar.
            $clienteAuxId = null;
        } else {
            $clienteNoMapeado = "doc_tipo={$docTipo} doc_nro={$docNro}";
        }

        // CC derivado del cliente (1:1).
        $ccId = $clienteAuxId
            ? DB::table('erp_centros_costo')->where('auxiliar_id', $clienteAuxId)->value('id')
            : null;

        // Idempotencia: (tipo, PV, número) ya existente como factura emitida.
        $existe = DB::table('erp_facturas_venta')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $tipoCbteCod)
            ->where('punto_venta_id', $puntoVenta->id)
            ->where('numero', $numero)
            ->whereNull('deleted_at')
            ->exists();
        if ($existe) {
            throw new DomainException(sprintf(
                'FACTURA_DUPLICADA: ya existe la factura (tipo=%d PV=%d nro=%d) en el sistema.',
                $tipoCbteCod, $puntoVentaNum, $numero
            ));
        }

        // CAE (opcional).
        $cae = trim((string) $this->get($r, $headerMap, 'cod. autorizacion')) ?: null;

        $factura = FacturaVenta::create([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $tipoCbteCod,
            'punto_venta_id' => $puntoVenta->id,
            'numero' => $numero,
            'cae' => $cae,
            'fecha_emision' => $fechaEmision,
            'auxiliar_id' => $clienteAuxId,
            'condicion_iva_id' => 1,
            'doc_tipo_afip' => $docTipo,
            'doc_nro' => $docNro ?: '0',
            'moneda_id' => 1,
            'cotizacion' => 1.0,
            'concepto_afip' => 2,
            'imp_neto_gravado' => $impNetoFinal,
            'imp_no_gravado' => $impNoGravado,
            'imp_exento' => $impExento,
            'imp_iva' => $impIvaFinal,
            'imp_iva_27' => $impIva27,
            'imp_iva_21' => $impIva21,
            'imp_iva_10_5' => $impIva10_5,
            'imp_iva_5' => $impIva5,
            'imp_iva_2_5' => $impIva2_5,
            'imp_neto_gravado_27' => $detalle['27']['neto'],
            'imp_neto_gravado_21' => $detalle['21']['neto'],
            'imp_neto_gravado_10_5' => $detalle['10_5']['neto'],
            'imp_neto_gravado_5' => $detalle['5']['neto'],
            'imp_neto_gravado_2_5' => $detalle['2_5']['neto'],
            'imp_tributos' => $otrosTributos,
            'imp_total' => $impTotal,
            'origen' => 'MIS_COMPROBANTES',
            'estado' => 'EMITIDA',
            'periodo_trabajado_texto' => $periodoTrabajado,
            'jurisdiccion_codigo' => $juris,
            'observaciones' => $observ,
            'centro_costo_id' => $ccId,
            'import_id' => $importId,
            'created_by_user_id' => $usuario->id,
        ]);

        // Asiento contable automático.
        try {
            $this->contabilizador->contabilizarVenta($factura->id, $empresaId, $usuario->id);
        } catch (\Throwable $e) {
            // No bloqueamos la importación si falla el asiento — registramos
            // como warning y dejamos la factura sin asiento_id (se puede
            // contabilizar después manualmente).
            return [
                'cliente_no_mapeado' => $clienteNoMapeado,
                'cliente_creado' => $clienteCreado,
                'warning' => sprintf('Factura #%d creada sin asiento: %s', $factura->id, $e->getMessage()),
            ];
        }

        return [
            'cliente_no_mapeado' => $clienteNoMapeado,
            'cliente_creado' => $clienteCreado,
            'warning' => null,
        ];
    }

    private function normalizarPeriodoTrabajado(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        if (preg_match('/^\d{4}-\d{2}(-Q[12])?$/', $s)) return $s;
        return null;
    }

    private function calcularFechaImputacion(string $fechaEmision, object $periodo): array
    {
        $inicio = $periodo->fecha_inicio instanceof \DateTimeInterface
            ? $periodo->fecha_inicio->format('Y-m-d')
            : (string) $periodo->fecha_inicio;
        $fin = $periodo->fecha_fin instanceof \DateTimeInterface
            ? $periodo->fecha_fin->format('Y-m-d')
            : (string) $periodo->fecha_fin;

        if ($fechaEmision < $inicio) {
            return ['fecha' => $inicio, 'warning' => null];
        }
        if ($fechaEmision > $fin) {
            $periodoNombre = sprintf('%02d/%d', (int) $periodo->mes, (int) $periodo->anio);
            throw new DomainException(
                "FECHA_POSTERIOR_AL_PERIODO: factura del {$fechaEmision} es posterior al período {$periodoNombre} ({$fin})."
            );
        }
        return ['fecha' => $fechaEmision, 'warning' => null];
    }

    private function leerArchivo(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'csv');
        if ($ext === 'xlsx' || $ext === 'xls') {
            $this->lastEncoding = 'XLSX';
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
            return $sheet->toArray(null, true, false, false);
        }

        $contenido = file_get_contents($path);
        if ($contenido === false) {
            throw new DomainException('FORMATO_INVALIDO: no se pudo abrir el archivo');
        }

        $encoding = null;
        if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
            $contenido = substr($contenido, 3);
            $encoding = 'UTF-8';
        } else {
            $detectado = mb_detect_encoding(
                $contenido, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true
            );
            $encoding = $detectado !== false ? $detectado : 'ISO-8859-1';
        }
        $this->lastEncoding = $encoding;
        if ($encoding !== 'UTF-8') {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', $encoding);
        }

        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $contenido) as $linea) {
            if ($linea === '') continue;
            $rows[] = str_getcsv($linea, ';');
        }
        return $rows;
    }

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

    private function get(array $r, array $headerMap, string $campo, $default = null)
    {
        return isset($headerMap[$campo]) ? ($r[$headerMap[$campo]] ?? $default) : $default;
    }

    private function detectarPeriodoNombre(string $nombre): ?string
    {
        if (preg_match('/(\d{4})[\-_]?(\d{2})/', $nombre, $m)) {
            return $m[1].$m[2];
        }
        return null;
    }

    private function parsearFloat($v): float
    {
        if ($v === null || $v === '') return 0.0;
        $s = trim((string) $v);
        if ($s === '') return 0.0;
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }

    private function parsearInt($v): int
    {
        if ($v === null || $v === '') return 0;
        return (int) preg_replace('/[^\d-]/', '', (string) $v);
    }

    private function parsearFecha($v, string $campo = 'fecha'): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '') return null;

        // AFIP a veces exporta YYYY-MM-DD, a veces dd/mm/yyyy. Soportamos ambos.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            throw new DomainException(
                "FECHA_FORMATO_INVALIDO: '{$campo}' = '{$s}' no tiene formato dd/mm/yyyy ni yyyy-mm-dd."
            );
        }
        [$_, $d, $mo, $y] = $m;
        try {
            $carbon = Carbon::createFromFormat('!d/m/Y', $s);
        } catch (\Throwable $e) {
            throw new DomainException("FECHA_PARSE_ERROR: '{$campo}' = '{$s}': ".$e->getMessage());
        }
        if ($carbon->day !== (int) $d || $carbon->month !== (int) $mo || $carbon->year !== (int) $y) {
            throw new DomainException("FECHA_INVALIDA: '{$campo}' = '{$s}' no es una fecha calendario válida.");
        }
        return $carbon->toDateString();
    }
}
