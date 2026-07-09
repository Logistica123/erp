<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Cotizacion;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\Parsers\ExtractoParseado;
use App\Erp\Services\Tesoreria\Parsers\ParserFactory;
use App\Erp\Services\Tesoreria\Parsers\MovimientoParseado;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Orquesta la importación de un archivo de extracto bancario.
 *
 * Flujo (SPEC 02 §7.1):
 *  1) Calcula hash SHA-256 del archivo (RN-12 idempotencia).
 *  2) Verifica que no haya un erp_extractos_bancarios con el mismo hash.
 *  3) Resuelve el parser según cuenta.banco.codigo_parser y parsea.
 *  4) Si la cuenta es USD, verifica que exista cotización para las fechas del
 *     rango (RN-28). Si falta, agrega warning pero NO aborta — los movimientos
 *     quedan en estado PENDIENTE hasta que se cargue la cotización.
 *  5) Guarda el archivo original en storage.
 *  6) Persiste erp_extractos_bancarios (cabecera) + N erp_movimientos_bancarios
 *     en una transacción. Usa INSERT ... ON DUPLICATE KEY UPDATE sobre el
 *     hash_linea para dedupe entre extractos del mismo período.
 *  7) Aplica reglas de erp_conciliacion_reglas (RN-15) para auto-etiquetar.
 *  8) Devuelve un resumen con counts + warnings.
 */
class ExtractoImporterService
{
    public function __construct(
        private readonly ParserFactory $factory,
        private readonly AuditLogger $audit,
        private readonly MatchingContraparteService $matcher,
        private readonly ExtractoTipoService $tipoSvc,
        private readonly \App\Erp\Services\Conciliacion\MatchingAutoService $matchingAuto,
        private readonly \App\Erp\Services\Conciliacion\EmparejarEspejosService $espejos,
    ) {}

    /** CUIT propio (Logística Argentina SRL) — transferencias entre cuentas propias. */
    public const CUIT_PROPIO = '30717060985';

    /**
     * @return array{
     *   extracto_id:int,
     *   movimientos_importados:int,
     *   movimientos_duplicados:int,
     *   etiquetados_auto:int,
     *   pendientes:int,
     *   pasantes_mp:int,
     *   warnings:array<int,string>,
     * }
     */
    public function importar(string $pathTemporal, CuentaBancaria $cuenta, User $usuario, string $nombreArchivo): array
    {
        $cuenta->loadMissing(['banco', 'moneda']);
        if (! $cuenta->banco) {
            throw new DomainException('CUENTA_SIN_BANCO: la cuenta no tiene banco asociado');
        }

        // v1.48 Anexo B Fix 2 — Brubank exporta las 2 cuentas (CC + Remunerada)
        // en el mismo archivo. Se sube UNA SOLA VEZ y el parser distribuye los
        // movimientos a cada cuenta bancaria según la columna `Cuenta`.
        if (in_array($cuenta->banco->codigo_parser, ['BRUBANK_CC', 'BRUBANK_REM'], true)) {
            return $this->importarBrubankCombinado($pathTemporal, $cuenta, $usuario, $nombreArchivo);
        }

        $hashArchivo = \App\Erp\Services\Tesoreria\Parsers\AbstractParser::hashArchivo($pathTemporal);

        // RN-12 idempotencia (UK por cuenta + hash desde CB-2: Brubank
        // exporta 2 cuentas en el mismo CSV, cada una se importa por separado).
        $existente = ExtractoBancario::where('cuenta_bancaria_id', $cuenta->id)
            ->where('hash_archivo', $hashArchivo)->first();
        if ($existente) {
            throw new DomainException(sprintf(
                'EXTRACTO_DUPLICADO: ya fue importado el %s para esta cuenta (id=%d)',
                $existente->importado_at?->format('d/m/Y H:i') ?? '?',
                $existente->id
            ));
        }

        // v1.48 Bloques I/J — Brubank y MercadoPago ahora exportan .xlsx. Los
        // parsers leen CSV (`;`), así que si llega un Excel lo convertimos a un
        // CSV temporal preservando el formato MOSTRADO de cada celda (coma
        // decimal, fechas DD-MM-YY) que es lo que esperan los parsers.
        [$pathParse, $esTmp] = $this->normalizarArchivoParseable($pathTemporal, $nombreArchivo);
        try {
            $parser = $this->factory->make($cuenta->banco->codigo_parser);
            $parseado = $parser->parse($pathParse, $cuenta);
        } finally {
            if ($esTmp && is_file($pathParse)) @unlink($pathParse);
        }

        $warnings = $parseado->errores;

        // RN-28 cotización USD
        if ($cuenta->moneda?->codigo === 'USD') {
            $faltantes = $this->fechasSinCotizacion($cuenta->empresa_id, $parseado);
            if (! empty($faltantes)) {
                $warnings[] = sprintf(
                    'COTIZACION_FALTANTE: sin cotización USD OFICIAL para %d fecha(s): %s. Los movimientos quedan en PENDIENTE hasta cargar la cotización.',
                    count($faltantes),
                    implode(', ', array_slice($faltantes, 0, 5))
                );
            }
        }

        // Guardar archivo
        $rutaStorage = $this->guardarArchivo($pathTemporal, $cuenta, $hashArchivo, $nombreArchivo);

        $resumen = $this->procesarExtractoCuenta($parseado, $cuenta, $usuario, $hashArchivo, $rutaStorage, $nombreArchivo);

        // v1.48 Bloque B — emparejar espejos de transferencias internas a nivel
        // empresa (el espejo puede haber venido en una importación anterior de
        // otra cuenta). Fuera de la transacción de import: su propio asiento.
        $espejos = ['emparejados' => 0, 'ambiguos' => 0, 'sin_espejo' => 0];
        try {
            $espejos = $this->espejos->emparejarEspejos($cuenta->empresa_id, $usuario->id);
        } catch (\Throwable $e) {
            \Log::warning('emparejarEspejos falló tras import: '.$e->getMessage());
        }

        $this->audit->logEvento(
            accion: 'EXTRACTO_IMPORTADO',
            modulo: 'tesoreria',
            descripcion: sprintf(
                'Extracto %s cuenta=%s mov=%d etiquetados=%d pendientes=%d dup=%d',
                $nombreArchivo,
                $cuenta->codigo,
                count($parseado->movimientos),
                $resumen['etiquetados_auto'],
                $resumen['pendientes'],
                $resumen['movimientos_duplicados']
            ),
            empresaId: $cuenta->empresa_id,
        );

        return [
            'extracto_id' => $resumen['extracto_id'],
            'movimientos_importados' => $resumen['movimientos_importados'],
            'movimientos_duplicados' => $resumen['movimientos_duplicados'],
            'etiquetados_auto' => $resumen['etiquetados_auto'],
            'pendientes' => $resumen['pendientes'],
            'pasantes_mp' => $resumen['pasantes_mp'],
            'transferencias_internas' => $resumen['transferencias_internas'],
            'espejos_emparejados' => $espejos['emparejados'],
            // v1.49 — auto-vinculación de descuentos de cheque.
            'descuentos_vinculados' => $resumen['descuentos_vinculados'] ?? 0,
            'descuentos_ambiguos' => $resumen['descuentos_ambiguos'] ?? 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * Núcleo de import por cuenta: crea el extracto, persiste, marca
     * transferencias internas y corre las pasadas de matching. Reusado por el
     * import normal y por el combinado de Brubank.
     *
     * @return array<string,mixed>
     */
    private function procesarExtractoCuenta(ExtractoParseado $parseado, CuentaBancaria $cuenta, User $usuario, string $hashArchivo, string $rutaStorage, string $nombreArchivo): array
    {
        return DB::transaction(function () use ($parseado, $cuenta, $usuario, $hashArchivo, $rutaStorage, $nombreArchivo) {
            $extracto = ExtractoBancario::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'fecha_desde' => $parseado->fechaDesde,
                'fecha_hasta' => $parseado->fechaHasta,
                'hash_archivo' => $hashArchivo,
                'nombre_archivo' => $nombreArchivo,
                'ruta_archivo' => $rutaStorage,
                'saldo_inicial' => $parseado->saldoInicial,
                'saldo_final' => $parseado->saldoFinal,
                'cant_movimientos' => count($parseado->movimientos),
                'importado_por_user_id' => $usuario->id,
                'importado_at' => now(),
                'observaciones' => empty($parseado->errores) ? null : implode("\n", $parseado->errores),
            ]);

            $counts = $this->persistirMovimientos($extracto->id, $cuenta, $parseado->movimientos);

            // v1.48 Bloque B — marcar transferencias internas (CUIT propio) ANTES
            // del matching, para que las pasadas de etiquetado las salteen.
            $transfInternas = $this->marcarTransferenciasInternas($extracto->id);

            // CM-3: Pasada de matching contraparte + detección de pasante MP.
            $matching = $this->aplicarMatching($extracto->id, $cuenta);
            $pasantes = $this->detectarPasanteMp($extracto->id, $cuenta);

            // v1.45: pasada de matching automático con extractor CUIT sobre los
            // movimientos que quedaron PENDIENTE (ICBC-COBRO-TRF / ICBC-PAGO-TRF).
            $matchAuto = $this->aplicarMatchingAuto($extracto->id, $cuenta);

            // v1.49 Bloque B — auto-vinculación de créditos a asientos de
            // descuento de cheque existentes (match perfecto ±3 días + único
            // candidato). Los ambiguos quedan PENDIENTE y se informan.
            $descuentos = app(\App\Erp\Services\Conciliacion\AutoVincularDescuentosService::class)
                ->run($extracto->id);

            return [
                'extracto_id' => $extracto->id,
                'cant_total' => count($parseado->movimientos),
                'movimientos_importados' => $counts['movimientos_importados'],
                'movimientos_duplicados' => $counts['movimientos_duplicados'],
                'etiquetados_auto' => $matching['etiquetados'],
                'match_auto_cuit' => $matchAuto,
                'pendientes' => $counts['movimientos_importados'] - $matching['etiquetados'] - $matchAuto - $descuentos['vinculados'],
                'pasantes_mp' => $pasantes,
                'transferencias_internas' => $transfInternas,
                'descuentos_vinculados' => $descuentos['vinculados'],
                'descuentos_ambiguos' => $descuentos['ambiguos'],
            ];
        });
    }

    /**
     * v1.48 Anexo B Fix 2 — import combinado de Brubank: una sola carga del
     * archivo distribuye los movimientos a las 2 cuentas bancarias (Cuenta
     * corriente → BRUBANK_CC, Cuenta remunerada → BRUBANK_REM) según la columna
     * `Cuenta`. Cada parser-subclase ya filtra sus filas; acá iteramos ambas
     * cuentas de la empresa. Idempotencia: rechaza si el hash ya existe en
     * cualquiera de las 2 cuentas.
     *
     * @return array<string,mixed>
     */
    private function importarBrubankCombinado(string $pathTemporal, CuentaBancaria $cuenta, User $usuario, string $nombreArchivo): array
    {
        $hashArchivo = \App\Erp\Services\Tesoreria\Parsers\AbstractParser::hashArchivo($pathTemporal);

        // Resolver las 2 cuentas Brubank de la empresa (CC + Remunerada).
        $cuentas = CuentaBancaria::with('banco', 'moneda')
            ->where('empresa_id', $cuenta->empresa_id)
            ->whereHas('banco', fn ($q) => $q->whereIn('codigo_parser', ['BRUBANK_CC', 'BRUBANK_REM']))
            ->get()
            ->sortBy(fn ($c) => $c->banco->codigo_parser) // CC antes que REM
            ->values();
        if ($cuentas->isEmpty()) {
            throw new DomainException('BRUBANK_SIN_CUENTAS: no hay cuentas Brubank configuradas en la empresa');
        }

        // Idempotencia: si el archivo ya se importó a cualquiera de las cuentas.
        $dup = ExtractoBancario::whereIn('cuenta_bancaria_id', $cuentas->pluck('id'))
            ->where('hash_archivo', $hashArchivo)->first();
        if ($dup) {
            throw new DomainException(sprintf(
                'EXTRACTO_DUPLICADO: este archivo Brubank ya fue importado el %s (extracto #%d)',
                $dup->importado_at?->format('d/m/Y H:i') ?? '?', $dup->id
            ));
        }

        // Convertir xlsx→CSV una sola vez; ambos parsers leen el mismo CSV.
        [$pathParse, $esTmp] = $this->normalizarArchivoParseable($pathTemporal, $nombreArchivo);

        $resumenes = [];
        $warnings = [];
        try {
            foreach ($cuentas as $cb) {
                $parser = $this->factory->make($cb->banco->codigo_parser);
                try {
                    $parseado = $parser->parse($pathParse, $cb);
                } catch (DomainException $e) {
                    // Una cuenta puede no tener filas en el archivo: se omite.
                    if (str_contains($e->getMessage(), 'no hay filas')) {
                        $warnings[] = "Sin movimientos para {$cb->codigo} en el archivo.";
                        continue;
                    }
                    throw $e;
                }
                $warnings = array_merge($warnings, $parseado->errores);
                $rutaStorage = $this->guardarArchivo($pathTemporal, $cb, $hashArchivo, $nombreArchivo);
                $resumenes[$cb->codigo] = $this->procesarExtractoCuenta($parseado, $cb, $usuario, $hashArchivo, $rutaStorage, $nombreArchivo);
            }
        } finally {
            if ($esTmp && is_file($pathParse)) @unlink($pathParse);
        }

        if (empty($resumenes)) {
            throw new DomainException('BRUBANK_SIN_MOVIMIENTOS: el archivo no tenía filas para ninguna cuenta Brubank');
        }

        // Emparejar espejos una sola vez (nivel empresa).
        $espejos = ['emparejados' => 0];
        try {
            $espejos = $this->espejos->emparejarEspejos($cuenta->empresa_id, $usuario->id);
        } catch (\Throwable $e) {
            \Log::warning('emparejarEspejos falló tras import Brubank: '.$e->getMessage());
        }

        $totImport = array_sum(array_map(fn ($r) => $r['movimientos_importados'], $resumenes));
        $totDup = array_sum(array_map(fn ($r) => $r['movimientos_duplicados'], $resumenes));
        $totEtiq = array_sum(array_map(fn ($r) => $r['etiquetados_auto'], $resumenes));
        $totPend = array_sum(array_map(fn ($r) => $r['pendientes'], $resumenes));

        $this->audit->logEvento(
            accion: 'EXTRACTO_IMPORTADO',
            modulo: 'tesoreria',
            descripcion: sprintf('Brubank combinado %s → %s · importados=%d etiquetados=%d pendientes=%d',
                $nombreArchivo, implode('+', array_keys($resumenes)), $totImport, $totEtiq, $totPend),
            empresaId: $cuenta->empresa_id,
        );

        return [
            'extracto_id' => $resumenes[array_key_first($resumenes)]['extracto_id'],
            'movimientos_importados' => $totImport,
            'movimientos_duplicados' => $totDup,
            'etiquetados_auto' => $totEtiq,
            'pendientes' => $totPend,
            'pasantes_mp' => 0,
            'transferencias_internas' => array_sum(array_map(fn ($r) => $r['transferencias_internas'], $resumenes)),
            'espejos_emparejados' => $espejos['emparejados'],
            'distribucion' => array_map(fn ($r) => $r['movimientos_importados'], $resumenes),
            'warnings' => $warnings,
        ];
    }

    /**
     * v1.48 Bloques I/J — si el archivo es Excel (.xlsx/.xls) lo convierte a un
     * CSV temporal `;`-separado usando el valor MOSTRADO de cada celda (respeta
     * la máscara: coma decimal, fechas DD-MM-YY). Devuelve [path, esTemporal].
     * CSV/TXT pasan tal cual.
     *
     * @return array{0:string,1:bool}
     */
    private function normalizarArchivoParseable(string $path, string $nombreArchivo): array
    {
        $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
        $esExcel = in_array($ext, ['xlsx', 'xls'], true);
        if (! $esExcel) {
            // Detección por firma ZIP (PK\x03\x04) para uploads sin extensión.
            $fh = @fopen($path, 'rb');
            if ($fh) {
                $sig = fread($fh, 4);
                fclose($fh);
                $esExcel = $sig === "PK\x03\x04" && $ext !== 'csv' && $ext !== 'txt';
            }
        }
        if (! $esExcel) {
            return [$path, false];
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false); // necesitamos máscaras de formato
        $ss = $reader->load($path);
        $sheet = $ss->getSheet(0); // primera hoja, sin asumir nombre (D-47/J)
        $maxRow = $sheet->getHighestDataRow();
        $maxColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $tmp = tempnam(sys_get_temp_dir(), 'erp_xlsx_').'.csv';
        $out = fopen($tmp, 'w');
        for ($r = 1; $r <= $maxRow; $r++) {
            $campos = [];
            for ($c = 1; $c <= $maxColIdx; $c++) {
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$r;
                $val = (string) $sheet->getCell($coord)->getFormattedValue();
                $campos[] = '"'.str_replace('"', '""', $val).'"';
            }
            fwrite($out, implode(';', $campos)."\r\n");
        }
        fclose($out);
        $ss->disconnectWorksheets();
        unset($ss);

        return [$tmp, true];
    }

    /**
     * v1.48 Bloque B — marca como transferencia interna los movimientos del
     * extracto cuya contraparte es el propio CUIT. Quedan en
     * PENDIENTE_TRANSF_INTERNA para que el emparejado de espejos los resuelva.
     */
    private function marcarTransferenciasInternas(int $extractoId): int
    {
        return DB::table('erp_movimientos_bancarios')
            ->where('extracto_id', $extractoId)
            ->where('estado', 'PENDIENTE')
            ->whereRaw("REPLACE(REPLACE(COALESCE(cuit_contraparte,''),'-',''),' ','') = ?", [self::CUIT_PROPIO])
            ->update(['es_transferencia_interna' => 1, 'estado' => 'PENDIENTE_TRANSF_INTERNA']);
    }

    /**
     * Persiste movimientos con dedup por (cuenta_bancaria_id, hash_linea).
     * Insertados todos como PENDIENTE — el matching ocurre en una segunda
     * pasada via aplicarMatching() para que use MatchingContraparteService.
     *
     * @param  array<int, MovimientoParseado>  $movs
     * @return array{movimientos_importados:int, movimientos_duplicados:int}
     */
    private function persistirMovimientos(int $extractoId, CuentaBancaria $cuenta, array $movs): array
    {
        if (empty($movs)) {
            return ['movimientos_importados' => 0, 'movimientos_duplicados' => 0];
        }

        $now = now();
        $importados = 0;
        $duplicados = 0;

        $bancoCodigo = $cuenta->banco->codigo_parser ?? null;
        foreach ($movs as $m) {
            // v1.27 Sprint B — inferir tipo_operativo del movimiento.
            $tipoOp = $this->tipoSvc->inferir(
                $bancoCodigo, $m->comprobanteBanco ?? null, $m->concepto,
                (float) ($m->debito ?? 0), (float) ($m->credito ?? 0),
            );

            $affected = DB::table('erp_movimientos_bancarios')->upsert(
                [[
                    'extracto_id' => $extractoId,
                    'cuenta_bancaria_id' => $cuenta->id,
                    'fecha' => $m->fecha->toDateString(),
                    'concepto' => $m->concepto,
                    'comprobante_banco' => $m->comprobanteBanco,
                    'debito' => $m->debito ?? 0,
                    'credito' => $m->credito ?? 0,
                    'saldo' => $m->saldo,
                    'estado' => 'PENDIENTE',
                    'tipo_operativo' => $tipoOp,
                    'cuit_contraparte' => $m->cuitContraparte,
                    'nombre_contraparte' => $m->nombreContraparte,
                    'referencia_externa' => $m->referencia,
                    'hash_linea' => $m->hashLinea,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['cuenta_bancaria_id', 'hash_linea'],
                ['extracto_id', 'tipo_operativo', 'updated_at']
            );

            // upsert() en Laravel devuelve 1 insert, 2 update (MySQL).
            if ($affected === 2) {
                $duplicados++;
            } else {
                $importados++;
            }
        }

        return [
            'movimientos_importados' => $importados,
            'movimientos_duplicados' => $duplicados,
        ];
    }

    /**
     * Pasada de matching: por cada movimiento PENDIENTE del extracto, llama a
     * MatchingContraparteService y, si la confianza es >= 50, escribe los
     * resultados en la fila. Confianza >= 80 promueve a CONCILIADO; entre 50
     * y 79 queda ETIQUETADO (espera revisión); < 50 queda PENDIENTE.
     *
     * @return array{etiquetados:int, conciliados:int}
     */
    /**
     * v1.45 — Pasada de matching automático con extractor CUIT sobre los
     * movimientos PENDIENTE. Los lleva a MATCH_AUTO si una regla con
     * matching_auto_factura identifica al auxiliar (y, si puede, la factura).
     */
    private function aplicarMatchingAuto(int $extractoId, CuentaBancaria $cuenta): int
    {
        $movs = MovimientoBancario::where('extracto_id', $extractoId)
            ->where('cuenta_bancaria_id', $cuenta->id)
            ->where('estado', MovimientoBancario::ESTADO_PENDIENTE)
            ->with('cuentaBancaria')
            ->get();
        $n = 0;
        foreach ($movs as $mov) {
            if ($this->matchingAuto->intentarMatching($mov)) $n++;
        }
        return $n;
    }

    private function aplicarMatching(int $extractoId, CuentaBancaria $cuenta): array
    {
        $movs = MovimientoBancario::where('extracto_id', $extractoId)
            ->where('cuenta_bancaria_id', $cuenta->id)
            ->where('estado', 'PENDIENTE')
            ->with('cuentaBancaria')
            ->get();

        $etiquetados = 0;
        $conciliados = 0;

        foreach ($movs as $mov) {
            $r = $this->matcher->matchear($mov);
            if (($r['confianza_match'] ?? 0) < 50) {
                continue;
            }

            $estado = $r['confianza_match'] >= 80 ? 'ETIQUETADO' : 'ETIQUETADO';
            // Nota: dejamos ETIQUETADO en ambos casos. La promoción a
            // CONCILIADO se hace cuando se genera el asiento (no por confianza
            // sola). Distinguimos por confianza_match para la UI.

            $mov->update([
                'cuit_contraparte'   => $r['cuit_contraparte']   ?? $mov->cuit_contraparte,
                'nombre_contraparte' => $r['nombre_contraparte'] ?? $mov->nombre_contraparte,
                'persona_id'         => $r['persona_id'],
                'cliente_id'         => $r['cliente_id'],
                'cuenta_propia_id'   => $r['cuenta_propia_id'],
                'referencia_externa' => $r['referencia_externa'] ?? $mov->referencia_externa,
                'regla_aplicada_id'  => $r['regla_aplicada_id'],
                'cuenta_contable_propuesta_id' => $r['cuenta_contable_propuesta_id']
                    ?? $mov->cuenta_contable_propuesta_id,
                'confianza_match'    => $r['confianza_match'],
                'estado'             => $estado,
                'etiqueta_sugerida'  => $r['estrategia'] ?? null,
            ]);

            $etiquetados++;
            if ($r['confianza_match'] >= 80) {
                $conciliados++;
            }
        }

        return ['etiquetados' => $etiquetados, 'conciliados' => $conciliados];
    }

    /**
     * Detecta operaciones pasantes MP: pares "Ingreso de dinero" + "Pago de
     * servicio" con misma `referencia_externa` (REFERENCE_ID en MP) e importes
     * opuestos exactos. Marca ambos como ETIQUETADO con etiqueta_sugerida =
     * "PASANTE_MP" y nombre_contraparte agrupado para que el operador los
     * concilie como un solo asiento agente de cobro.
     *
     * Sólo se aplica si el banco tiene codigo_parser="MP".
     *
     * @return int cantidad de pares detectados
     */
    private function detectarPasanteMp(int $extractoId, CuentaBancaria $cuenta): int
    {
        $codigo = mb_strtoupper((string) $cuenta->banco?->codigo_parser);
        if ($codigo !== 'MP' && $codigo !== 'MERCADO_PAGO') {
            return 0;
        }

        $movs = MovimientoBancario::where('extracto_id', $extractoId)
            ->whereNotNull('referencia_externa')
            ->orderBy('referencia_externa')
            ->get()
            ->groupBy('referencia_externa');

        $pares = 0;
        foreach ($movs as $refId => $grupo) {
            if ($grupo->count() < 2) continue;

            $credito = $grupo->firstWhere(fn ($m) => (float) $m->credito > 0);
            $debito  = $grupo->firstWhere(fn ($m) => (float) $m->debito  > 0);
            if (! $credito || ! $debito) continue;

            $impC = (float) $credito->credito;
            $impD = (float) $debito->debito;
            if (abs($impC - $impD) > 0.01) continue;

            $conceptoC = mb_strtoupper((string) $credito->concepto);
            $conceptoD = mb_strtoupper((string) $debito->concepto);
            $esIngreso = str_contains($conceptoC, 'INGRESO DE DINERO');
            $esServicio = str_contains($conceptoD, 'PAGO DE SERVICIO');
            if (! $esIngreso || ! $esServicio) continue;

            // Identifica el servicio (ej. "Pago de servicio Aguas de Corrientes" → "Aguas de Corrientes").
            $servicio = trim((string) preg_replace('/^pago\s+de\s+servicio\s*/iu', '', $debito->concepto));

            foreach ([$credito, $debito] as $m) {
                $m->update([
                    'estado' => 'ETIQUETADO',
                    'etiqueta_sugerida' => 'PASANTE_MP',
                    'nombre_contraparte' => $servicio !== '' ? $servicio : 'Pasante MP',
                    'confianza_match' => max((int) $m->confianza_match, 75),
                ]);
            }
            $pares++;
        }

        return $pares;
    }

    /**
     * @return array<int, string>
     */
    private function fechasSinCotizacion(int $empresaId, ExtractoParseado $e): array
    {
        $fechas = [];
        foreach ($e->movimientos as $m) {
            $fechas[$m->fecha->toDateString()] = true;
        }
        $fechas = array_keys($fechas);

        $conCotizacion = Cotizacion::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'OFICIAL')
            ->whereHas('moneda', fn ($q) => $q->where('codigo', 'USD'))
            ->whereIn('fecha', $fechas)
            ->pluck('fecha')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->all();

        return array_diff($fechas, $conCotizacion);
    }

    private function guardarArchivo(string $pathTemporal, CuentaBancaria $cuenta, string $hashArchivo, string $nombreOriginal): string
    {
        $disk = Storage::disk('local');
        $destino = sprintf(
            'erp/tesoreria/extractos/%d/%s/%s__%s',
            $cuenta->empresa_id,
            $cuenta->codigo,
            substr($hashArchivo, 0, 12),
            $nombreOriginal
        );
        $contenido = file_get_contents($pathTemporal);
        if ($contenido === false) {
            throw new DomainException('FORMATO_INVALIDO: no se pudo leer el archivo temporal');
        }
        $disk->put($destino, $contenido);

        return $destino;
    }
}
