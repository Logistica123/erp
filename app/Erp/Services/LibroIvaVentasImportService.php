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

    /**
     * v1.46 — Aliases de columnas. AFIP exporta con headers variables entre
     * formatos. Para cada canónica probamos múltiples aliases hasta encontrar
     * uno. Lo que esté antes en la lista gana.
     */
    private const HEADER_ALIASES = [
        'fecha de emision' => ['fecha de emision', 'fecha emision', 'fecha'],
        'tipo de comprobante' => ['tipo de comprobante', 'tipo comprobante', 'tipo cbte', 'tipo de cbte'],
        'punto de venta' => ['punto de venta', 'pto. de venta', 'pto venta', 'pto. vta', 'pto de vta'],
        'numero desde' => ['numero desde', 'nro. desde', 'numero de comprobante', 'numero', 'nro. comprobante'],
        'tipo doc. receptor' => ['tipo doc. receptor', 'tipo doc receptor', 'tipo de documento', 'tipo documento'],
        'nro. doc. receptor' => ['nro. doc. receptor', 'nro doc receptor', 'cuit receptor', 'cuit', 'nro. documento'],
        'denominacion receptor' => ['denominacion receptor', 'denominacion', 'razon social receptor', 'razon social', 'apellido y nombre razon social'],
        'imp. total' => ['imp. total', 'imp total', 'importe total', 'total'],
        'imp. neto gravado' => ['imp. neto gravado', 'importe neto gravado', 'neto gravado'],
        'imp. neto no gravado' => ['imp. neto no gravado', 'importe neto no gravado', 'no gravado'],
        'imp. op. exentas' => ['imp. op. exentas', 'op. exentas', 'exento', 'imp. exento'],
        'iva' => ['iva', 'imp. iva', 'total iva'],
        'otros tributos' => ['otros tributos', 'otros tribu'],
        'cod. autorizacion' => ['cod. autorizacion', 'codigo autorizacion', 'cae', 'cae nro'],
        'cod jurisd' => ['cod jurisd', 'codigo jurisdiccion', 'jurisdiccion'],
        'comentario' => ['comentario', 'comentarios', 'observaciones', 'observacion'],
        'periodo trabajado' => ['periodo trabajado', 'periodo de trabajo', 'periodo'],
    ];

    /** Encoding detectado de la última lectura. */
    private ?string $lastEncoding = null;

    /** v1.46 — para diagnóstico en mensajes de error. */
    private array $lastHeadersDetectados = [];

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
            $disponibles = $this->lastHeadersDetectados;
            $resumen = empty($disponibles)
                ? '(ningún header detectado)'
                : implode(' | ', array_slice($disponibles, 0, 15)).(count($disponibles) > 15 ? ' …' : '');
            throw new DomainException(
                'HEADERS_FALTANTES: faltan columnas obligatorias: '.implode(', ', $faltantes)
                .'. Headers detectados: '.$resumen
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
        $ok = 0; $skipped = 0; $clientesCreados = 0; $duplicados = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $idx => $rowRaw) {
                $rowNum = $idx + 2;
                if (! $this->filaTieneDatos($rowRaw)) { $skipped++; continue; }

                try {
                    $r = $this->parsearFila($rowRaw, $headerMap, $empresaId, $periodo, $import->id, $usuario);
                    if (! empty($r['duplicado'])) {
                        // v1.50.4 — Duplicado se reporta como warning y suma a
                        // contador propio (no a $ok ni a $errores). El archivo
                        // sigue procesando normalmente.
                        $duplicados++;
                        $warnings[] = ['row' => $rowNum, 'motivo' => $r['warning']];
                        continue;
                    }
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
                'Import LIBRO_IVA_VENTAS #%d — %d ok, %d errores, %d clientes creados, %d duplicados saltados',
                $import->id, $ok, count($errores), $clientesCreados, $duplicados
            ),
            empresaId: $empresaId,
        );

        return [
            'import_id' => $import->id,
            'estado' => $estado,
            'stats' => [
                'totales' => count($errores) > 0 ? 0 : $ok,
                'skipped' => $skipped,
                'duplicados' => $duplicados, // v1.50.4
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
     * v1.30 — Modo "Control" del import de Ventas.
     *
     * NO inserta facturas. Lee el archivo de AFIP "Mis Comprobantes" + el período
     * (YYYY-MM) y compara contra lo cargado en `erp_facturas_venta`. Devuelve 4
     * buckets para que el operador resuelva manualmente.
     *
     * Clave de matching: (tipo_comprobante_cod, punto_venta_numero, numero).
     * Tolerancia importe: $1 (cualquier diff >= 1 entra a "coinciden_con_diff").
     *
     * @return array{
     *   periodo: string,
     *   coinciden: int,
     *   solo_sistema: list<array>,
     *   solo_afip: list<array>,
     *   coinciden_con_diff: list<array>,
     * }
     */
    public function controlar(
        string $pathTemporal,
        string $periodoYyyymm,
        int $empresaId = 1,
    ): array {
        if (! preg_match('/^\d{4}-\d{2}$/', $periodoYyyymm)) {
            throw new DomainException('PERIODO_INVALIDO: usar formato YYYY-MM.');
        }

        $rows = $this->leerArchivo($pathTemporal);
        if (empty($rows)) {
            throw new DomainException('FORMATO_INVALIDO: archivo vacío.');
        }

        $headerRaw = array_shift($rows);
        $headerMap = $this->mapearHeader($headerRaw);

        $faltantes = [];
        foreach (self::HEADERS_OBLIGATORIOS as $col) {
            if (! isset($headerMap[$col])) $faltantes[] = $col;
        }
        if (! empty($faltantes)) {
            throw new DomainException(
                'HEADERS_FALTANTES: faltan columnas obligatorias: '.implode(', ', $faltantes)
            );
        }

        // Index AFIP rows por clave.
        $afipPorClave = [];
        foreach ($rows as $idx => $r) {
            if (! $this->filaTieneDatos($r)) continue;
            $tipoCod = (int) $this->parsearInt($this->get($r, $headerMap, 'tipo de comprobante'));
            $pvNum   = (int) $this->parsearInt($this->get($r, $headerMap, 'punto de venta'));
            $numero  = (int) $this->parsearInt($this->get($r, $headerMap, 'numero desde'));
            if (! $tipoCod || ! $pvNum || ! $numero) continue;
            $clave = "{$tipoCod}|{$pvNum}|{$numero}";

            $fechaEmision = (string) $this->parsearFecha(
                $this->get($r, $headerMap, 'fecha de emision'), 'Fecha de Emisión',
            );
            $impTotal = $this->parsearFloat($this->get($r, $headerMap, 'imp. total'));
            $docTipo  = (int) $this->parsearInt($this->get($r, $headerMap, 'tipo doc. receptor'));
            $docNro   = trim((string) $this->get($r, $headerMap, 'nro. doc. receptor'));
            $razon    = trim((string) $this->get($r, $headerMap, 'denominacion receptor'));
            $cae      = trim((string) $this->get($r, $headerMap, 'cod. autorizacion')) ?: null;

            $afipPorClave[$clave] = [
                'tipo_comprobante_cod' => $tipoCod,
                'punto_venta_numero' => $pvNum,
                'numero' => $numero,
                'fecha_emision' => $fechaEmision,
                'doc_tipo' => $docTipo,
                'doc_nro' => $docNro,
                'razon_social' => $razon,
                'imp_total' => $impTotal,
                'cae' => $cae,
                'fila_origen' => $idx + 2,
            ];
        }

        // Sistema: facturas del período por mes de fecha_emision.
        $sistema = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
            ->leftJoin('erp_auxiliares as aux', 'aux.id', '=', 'fv.auxiliar_id')
            ->where('fv.empresa_id', $empresaId)
            ->whereNull('fv.deleted_at')
            ->where(DB::raw("DATE_FORMAT(fv.fecha_emision, '%Y-%m')"), $periodoYyyymm)
            ->get([
                'fv.id', 'fv.tipo_comprobante_id', 'fv.numero', 'fv.fecha_emision',
                'fv.imp_total', 'fv.cae', 'fv.estado', 'fv.origen',
                'tc.id as tc_id', 'pv.numero as pv_numero',
                'aux.nombre as cliente_nombre', 'aux.cuit as cliente_cuit',
            ]);

        $sistemaPorClave = [];
        foreach ($sistema as $row) {
            $clave = "{$row->tc_id}|{$row->pv_numero}|{$row->numero}";
            $sistemaPorClave[$clave] = $row;
        }

        $soloAfip = [];
        $soloSistema = [];
        $coincidenConDiff = [];
        $coinciden = 0;

        $clavesUnion = array_unique(array_merge(
            array_keys($afipPorClave), array_keys($sistemaPorClave),
        ));
        foreach ($clavesUnion as $clave) {
            $a = $afipPorClave[$clave] ?? null;
            $s = $sistemaPorClave[$clave] ?? null;
            if ($a && ! $s) {
                $soloAfip[] = $a;
            } elseif ($s && ! $a) {
                $soloSistema[] = [
                    'factura_id' => $s->id,
                    'tipo_comprobante_id' => $s->tipo_comprobante_id,
                    'punto_venta_numero' => $s->pv_numero,
                    'numero' => $s->numero,
                    'fecha_emision' => (string) $s->fecha_emision,
                    'imp_total' => (float) $s->imp_total,
                    'cae' => $s->cae,
                    'estado' => $s->estado,
                    'origen' => $s->origen,
                    'cliente_nombre' => $s->cliente_nombre,
                    'cliente_cuit' => $s->cliente_cuit,
                ];
            } else {
                // Ambos lados. Comparar imp_total con tolerancia $1.
                $diff = abs((float) $s->imp_total - (float) $a['imp_total']);
                if ($diff >= 1.0) {
                    $coincidenConDiff[] = [
                        'factura_id' => $s->id,
                        'tipo_comprobante_id' => $s->tipo_comprobante_id,
                        'punto_venta_numero' => $s->pv_numero,
                        'numero' => $s->numero,
                        'sistema' => [
                            'fecha_emision' => (string) $s->fecha_emision,
                            'imp_total' => (float) $s->imp_total,
                            'cae' => $s->cae,
                        ],
                        'afip' => [
                            'fecha_emision' => $a['fecha_emision'],
                            'imp_total' => $a['imp_total'],
                            'cae' => $a['cae'],
                        ],
                        'diff' => round($diff, 2),
                    ];
                } else {
                    $coinciden++;
                }
            }
        }

        return [
            'periodo' => $periodoYyyymm,
            'coinciden' => $coinciden,
            'solo_sistema' => $soloSistema,
            'solo_afip' => $soloAfip,
            'coinciden_con_diff' => $coincidenConDiff,
        ];
    }

    /**
     * v1.30 — Importa solo las facturas faltantes (las "solo en AFIP") detectadas
     * por el modo Control. Re-parsea el archivo y filtra por las claves indicadas.
     *
     * @param  list<array{tipo:int,pv:int,nro:int}>  $claves
     */
    public function importarFaltantesDesdeAfip(
        string $pathTemporal,
        string $nombreArchivo,
        array $claves,
        int $periodoImputacionId,
        User $usuario,
        int $empresaId = 1,
    ): array {
        if (empty($claves)) {
            throw new DomainException('SIN_CLAVES: no se indicaron facturas a importar.');
        }

        $periodo = DB::table('erp_periodos')->where('id', $periodoImputacionId)->first();
        if (! $periodo) throw new DomainException('PERIODO_NO_ENCONTRADO');
        if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            throw new DomainException('PERIODO_CERRADO: reabrilo desde Contabilidad → Períodos.');
        }

        $clavesSet = [];
        foreach ($claves as $k) {
            $tipo = (int) ($k['tipo'] ?? 0);
            $pv = (int) ($k['pv'] ?? 0);
            $nro = (int) ($k['nro'] ?? 0);
            if ($tipo && $pv && $nro) {
                $clavesSet["{$tipo}|{$pv}|{$nro}"] = true;
            }
        }
        if (empty($clavesSet)) {
            throw new DomainException('SIN_CLAVES: ninguna clave válida.');
        }

        $hash = hash_file('sha256', $pathTemporal);
        $rows = $this->leerArchivo($pathTemporal);
        $headerRaw = array_shift($rows);
        $headerMap = $this->mapearHeader($headerRaw);

        // Reutiliza el patrón de confirmar() pero filtrando filas.
        // El hash del modo Control debe ser único y ≤64 chars (la columna es
        // VARCHAR(64)). Hasheamos el combinado para que entre exacto en 64.
        $hashControl = hash('sha256', $hash.'-control-'.now()->format('YmdHis').uniqid());
        $import = LibroIvaVentasImport::create([
            'empresa_id' => $empresaId,
            'archivo_nombre' => mb_substr($nombreArchivo.' (control: importar faltantes)', 0, 250),
            'archivo_hash' => $hashControl,
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

        $errores = []; $warnings = []; $clientesNoMapeados = [];
        $ok = 0; $skipped = 0; $clientesCreados = 0; $duplicados = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $idx => $rowRaw) {
                $rowNum = $idx + 2;
                if (! $this->filaTieneDatos($rowRaw)) { $skipped++; continue; }

                $tipoCod = (int) $this->parsearInt($this->get($rowRaw, $headerMap, 'tipo de comprobante'));
                $pvNum   = (int) $this->parsearInt($this->get($rowRaw, $headerMap, 'punto de venta'));
                $numero  = (int) $this->parsearInt($this->get($rowRaw, $headerMap, 'numero desde'));
                $clave = "{$tipoCod}|{$pvNum}|{$numero}";
                if (! isset($clavesSet[$clave])) { $skipped++; continue; }

                try {
                    $r = $this->parsearFila($rowRaw, $headerMap, $empresaId, $periodo, $import->id, $usuario);
                    if (! empty($r['duplicado'])) {
                        $duplicados++;
                        $warnings[] = ['row' => $rowNum, 'motivo' => $r['warning']];
                        continue;
                    }
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
            accion: 'IMPORT_LIBRO_IVA_VENTAS_CONTROL',
            modulo: 'ventas',
            descripcion: sprintf(
                'Import faltantes via Control #%d — %d ok, %d errores, %d claves solicitadas',
                $import->id, $ok, count($errores), count($clavesSet),
            ),
            empresaId: $empresaId,
        );

        return [
            'import_id' => $import->id,
            'estado' => $estado,
            'stats' => [
                'totales' => count($errores) > 0 ? 0 : $ok,
                'skipped' => $skipped,
                'duplicados' => $duplicados,
                'errores' => count($errores),
                'warnings' => count($warnings),
                'clientes_creados' => count($errores) > 0 ? 0 : $clientesCreados,
                'clientes_no_mapeados' => count($errores) > 0 ? 0 : count($clientesNoMapeados),
                'claves_solicitadas' => count($clavesSet),
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
            // v1.50.2 — Upsert automático del punto de venta cuando no existe.
            // Antes (v1.45) rebotaba toda la fila. En la práctica, el contador
            // sube el archivo con TODOS los PVs históricos en uso — auto-crear
            // los faltantes con tipo_emision=MANUAL (porque vienen de facturas
            // ya emitidas, no van a salir nuevos CAEs por este PV) y activo=1.
            $pvId = DB::table('erp_puntos_venta')->insertGetId([
                'empresa_id' => $empresaId,
                'numero' => $puntoVentaNum,
                'nombre' => sprintf('PV %d — auto (import Libro IVA)', $puntoVentaNum),
                'tipo_emision' => 'MANUAL',
                'activo' => 1,
                'bloqueado' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $puntoVenta = (object) ['id' => $pvId];
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

        // v1.50.3 — Desglose por alícuota desde la AFIP "Mis Comprobantes Emitidos"
        // detalle. Columnas reales (post-mojibake fix):
        //   "Imp. Neto Gravado IVA 27%"  + "IVA 27%"
        //   "Imp. Neto Gravado IVA 21%"  + "IVA 21%"
        //   "Imp. Neto Gravado IVA 10,5%" + "IVA 10,5%"
        //   "Imp. Neto Gravado IVA 5%"   + "IVA 5%"
        //   "Imp. Neto Gravado IVA 2,5%" + "IVA 2,5%"
        //
        // Probamos varias variantes de los nombres porque AFIP usa coma o punto
        // como separador decimal según el export, y a veces no incluye espacios
        // entre "IVA" y el porcentaje.
        $tasasLabels = [
            '27'   => ['27'],
            '21'   => ['21'],
            '10_5' => ['10,5', '10.5'],
            '5'    => ['5'],
            '2_5'  => ['2,5', '2.5'],
        ];
        $factores = ['27'=>0.27, '21'=>0.21, '10_5'=>0.105, '5'=>0.05, '2_5'=>0.025];
        $detalle = [];
        $ivaDetalle = [];
        foreach ($tasasLabels as $key => $variantes) {
            $neto = 0.0;
            $iva = 0.0;
            foreach ($variantes as $v) {
                if ($neto == 0) {
                    $neto = $this->parsearFloat($this->get($r, $headerMap, "imp. neto gravado iva {$v}%"));
                }
                if ($iva == 0) {
                    $iva = $this->parsearFloat($this->get($r, $headerMap, "iva {$v}%"));
                }
            }
            $detalle[$key] = ['neto' => $neto, 'factor' => $factores[$key]];
            $ivaDetalle[$key] = $iva;
        }

        // v1.50.3 — IVA por alícuota: usar el monto del CSV si vino, sino
        // derivar del neto × factor. Antes solo derivábamos, pero la AFIP ya
        // lo trae explícito en "IVA X%" — preferir ese valor por exactitud.
        $impIva27 = $ivaDetalle['27'] > 0 ? round($ivaDetalle['27'], 2) : round($detalle['27']['neto'] * 0.27, 2);
        $impIva21 = $ivaDetalle['21'] > 0 ? round($ivaDetalle['21'], 2) : round($detalle['21']['neto'] * 0.21, 2);
        $impIva10_5 = $ivaDetalle['10_5'] > 0 ? round($ivaDetalle['10_5'], 2) : round($detalle['10_5']['neto'] * 0.105, 2);
        $impIva5 = $ivaDetalle['5'] > 0 ? round($ivaDetalle['5'], 2) : round($detalle['5']['neto'] * 0.05, 2);
        $impIva2_5 = $ivaDetalle['2_5'] > 0 ? round($ivaDetalle['2_5'], 2) : round($detalle['2_5']['neto'] * 0.025, 2);
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
            // v1.50.3 — Consumidor Final: usar auxiliar genérico "CF-GENERICO"
            // (creado en seed inicial). Antes dejábamos NULL y violaba NOT NULL.
            $clienteAuxId = DB::table('erp_auxiliares')
                ->where('codigo', 'CF-GENERICO')->value('id');
        } else {
            $clienteNoMapeado = "doc_tipo={$docTipo} doc_nro={$docNro}";
        }

        // v1.50.3 — Si después de todo seguimos sin auxiliar (ej: cliente sin
        // CUIT, sin razón social, doc_tipo desconocido), caemos al CF genérico
        // para no violar el NOT NULL del schema.
        if (! $clienteAuxId) {
            $clienteAuxId = DB::table('erp_auxiliares')
                ->where('codigo', 'CF-GENERICO')->value('id');
            if (! $clienteNoMapeado) {
                $clienteNoMapeado = "doc_tipo={$docTipo} doc_nro={$docNro} → CF-GENERICO";
            }
        }

        // CC derivado del cliente (1:1).
        $ccId = $clienteAuxId
            ? DB::table('erp_centros_costo')->where('auxiliar_id', $clienteAuxId)->value('id')
            : null;

        // v1.50.4 — Idempotencia: (tipo, PV, número) ya existente.
        // Antes (v1.45) tirábamos DomainException → atomicidad rebota TODO el
        // archivo. En ventas eso es contraproducente: el contador típicamente
        // re-sube un archivo con un mes más amplio y las facturas ya cargadas
        // bloqueaban todo. Ahora devolvemos como duplicado SKIPPED (warning,
        // no error). El resto del archivo entra.
        $existe = DB::table('erp_facturas_venta')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $tipoCbteCod)
            ->where('punto_venta_id', $puntoVenta->id)
            ->where('numero', $numero)
            ->whereNull('deleted_at')
            ->exists();
        if ($existe) {
            return [
                'cliente_no_mapeado' => null,
                'cliente_creado' => false,
                'warning' => sprintf(
                    'FACTURA_DUPLICADA: ya existe (tipo=%d PV=%d nro=%d) — saltada.',
                    $tipoCbteCod, $puntoVentaNum, $numero,
                ),
                'duplicado' => true,
            ];
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
        // v1.47 — Detección por magic bytes (Laravel uploads sin extensión).
        $fh = fopen($path, 'rb');
        $magic = $fh ? (string) fread($fh, 8) : '';
        if ($fh) fclose($fh);

        $extPath = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        $esXlsx = str_starts_with($magic, "PK\x03\x04") || $extPath === 'xlsx';
        $esXls = str_starts_with($magic, "\xD0\xCF\x11\xE0") || $extPath === 'xls';

        if ($esXlsx || $esXls) {
            $this->lastEncoding = 'XLSX';
            $reader = $esXlsx
                ? \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')
                : \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
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
        $byName = [];
        foreach ($row as $idx => $cell) {
            $norm = $this->normalizar((string) $cell);
            if ($norm) $byName[$norm] = $idx;
        }
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
        $this->lastHeadersDetectados = array_values(array_filter(array_map(
            fn ($c) => $this->normalizar((string) $c), $row,
        )));
        return $map;
    }

    private function normalizar(string $s): string
    {
        // v1.50 — Reparar mojibake UTF-8 doble: archivos XLSX exportados desde
        // sistemas que codificaron mal (UTF-8 → leído como Latin-1 → re-encodeado
        // como UTF-8) terminan con "Ã³" en vez de "ó", "Ã±" en vez de "ñ", etc.
        // El round-trip "tratar UTF-8 como Latin-1, re-encodear como UTF-8"
        // lo revierte. Solo aplica si detectamos el patrón típico.
        $s = $this->fixMojibakeUtf8($s);
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n',
        ]);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    /**
     * v1.50 — Detecta y revierte UTF-8 doblemente codificado.
     *
     * Bug v1.50 inicial: la regex /Ã[\x80-\xBF]/ activaba modo UTF-8 implícito
     * en PCRE (por tener "Ã" en el patrón) y el rango [\x80-\xBF] pasaba a
     * interpretarse como codepoints en vez de bytes — no matcheaba nada.
     *
     * Fix: usar strpos a nivel byte (sin PCRE) buscando secuencias C3 83 (Ã)
     * o C3 A3 (ã) seguidas por C2 (segundo byte del char mojibake'd). El
     * round-trip "interpretar UTF-8 como Latin-1, validar como UTF-8" hace
     * el resto.
     */
    private function fixMojibakeUtf8(string $s): string
    {
        // Detectar "Ã" (C3 83) o "ã" (C3 A3) seguido por C2 o C3 (segundo
        // char del par mojibake'd: ³, º, ±, etc — todos empiezan con C2).
        $hayMojibake = strpos($s, "\xC3\x83\xC2") !== false
            || strpos($s, "\xC3\x83\xC3") !== false
            || strpos($s, "\xC3\xA3\xC2") !== false
            || strpos($s, "\xC3\xA3\xC3") !== false;
        if (! $hayMojibake) {
            return $s;
        }
        $candidate = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if ($candidate === false || $candidate === '') {
            return $s;
        }
        return mb_check_encoding($candidate, 'UTF-8') ? $candidate : $s;
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
        // v1.48 — Native floats/ints (XLSX) van directos sin transformar
        // (sino el str_replace '.' borra el decimal y queda 100x el valor).
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '') return 0.0;
        $tieneComa = strpos($s, ',') !== false;
        $tienePunto = strpos($s, '.') !== false;
        if ($tieneComa && $tienePunto) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($tieneComa) {
            $s = str_replace(',', '.', $s);
        } else {
            if (substr_count($s, '.') > 1) {
                $s = str_replace('.', '', $s);
            }
        }
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

        // v1.48 — Excel serial date (XLSX setReadDataOnly).
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            $n = (float) $s;
            if ($n >= 1 && $n <= 2958465) {
                try {
                    $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
                    $s = $dt->format('d/m/Y');
                } catch (\Throwable $e) {
                    // dejamos que abajo tire el error de formato.
                }
            }
        }

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
