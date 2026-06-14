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
    ) {}

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

        $parser = $this->factory->make($cuenta->banco->codigo_parser);
        $parseado = $parser->parse($pathTemporal, $cuenta);

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

        $resumen = DB::transaction(function () use ($parseado, $cuenta, $usuario, $hashArchivo, $rutaStorage, $nombreArchivo) {
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

            // CM-3: Pasada de matching contraparte + detección de pasante MP.
            $matching = $this->aplicarMatching($extracto->id, $cuenta);
            $pasantes = $this->detectarPasanteMp($extracto->id, $cuenta);

            // v1.45: pasada de matching automático con extractor CUIT sobre los
            // movimientos que quedaron PENDIENTE (ICBC-COBRO-TRF / ICBC-PAGO-TRF).
            $matchAuto = $this->aplicarMatchingAuto($extracto->id, $cuenta);

            return [
                'extracto_id' => $extracto->id,
                'cant_total' => count($parseado->movimientos),
                'movimientos_importados' => $counts['movimientos_importados'],
                'movimientos_duplicados' => $counts['movimientos_duplicados'],
                'etiquetados_auto' => $matching['etiquetados'],
                'match_auto_cuit' => $matchAuto,
                'pendientes' => $counts['movimientos_importados'] - $matching['etiquetados'] - $matchAuto,
                'pasantes_mp' => $pasantes,
            ];
        });

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
            'warnings' => $warnings,
        ];
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
