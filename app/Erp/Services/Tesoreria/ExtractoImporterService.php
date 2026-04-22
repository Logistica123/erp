<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Cotizacion;
use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
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
    ) {}

    /**
     * @return array{
     *   extracto_id:int,
     *   movimientos_importados:int,
     *   movimientos_duplicados:int,
     *   etiquetados_auto:int,
     *   pendientes:int,
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

        // RN-12 idempotencia
        $existente = ExtractoBancario::where('hash_archivo', $hashArchivo)->first();
        if ($existente) {
            throw new DomainException(sprintf(
                'EXTRACTO_DUPLICADO: ya fue importado el %s (id=%d)',
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

            return [
                'extracto_id' => $extracto->id,
                'cant_total' => count($parseado->movimientos),
                ...$counts,
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
            'warnings' => $warnings,
        ];
    }

    /**
     * Persiste movimientos con dedup por (cuenta_bancaria_id, hash_linea).
     * ON DUPLICATE KEY UPDATE actualiza extracto_id para trazabilidad de
     * cuál import trajo la última copia.
     *
     * @param  array<int, MovimientoParseado>  $movs
     * @return array{movimientos_importados:int, movimientos_duplicados:int, etiquetados_auto:int, pendientes:int}
     */
    private function persistirMovimientos(int $extractoId, CuentaBancaria $cuenta, array $movs): array
    {
        if (empty($movs)) {
            return ['movimientos_importados' => 0, 'movimientos_duplicados' => 0, 'etiquetados_auto' => 0, 'pendientes' => 0];
        }

        $reglas = $this->reglasActivas($cuenta->empresa_id);
        $now = now();

        $importados = 0;
        $duplicados = 0;
        $etiquetados = 0;
        $pendientes = 0;

        foreach ($movs as $m) {
            $match = $this->aplicarReglas($m, $reglas);
            $estado = $match ? 'ETIQUETADO' : 'PENDIENTE';

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
                    'estado' => $estado,
                    'etiqueta_sugerida' => $match['etiqueta'] ?? null,
                    'cuenta_contable_propuesta_id' => $match['cuenta_contable_id'] ?? null,
                    'hash_linea' => $m->hashLinea,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['cuenta_bancaria_id', 'hash_linea'],
                ['extracto_id', 'updated_at']
            );

            // upsert() en Laravel devuelve cantidad de filas afectadas: 1 insert, 2 update (MySQL).
            if ($affected === 2) {
                $duplicados++;
            } else {
                $importados++;
                if ($match) {
                    $etiquetados++;
                } else {
                    $pendientes++;
                }
            }
        }

        return [
            'movimientos_importados' => $importados,
            'movimientos_duplicados' => $duplicados,
            'etiquetados_auto' => $etiquetados,
            'pendientes' => $pendientes,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, ConciliacionRegla>
     */
    private function reglasActivas(int $empresaId)
    {
        return ConciliacionRegla::where('empresa_id', $empresaId)
            ->where('activa', true)
            ->orderBy('orden_prioridad')
            ->get();
    }

    /**
     * Devuelve {etiqueta, cuenta_contable_id} del primer match o null.
     *
     * @param  \Illuminate\Support\Collection<int, ConciliacionRegla>  $reglas
     * @return array{etiqueta:string, cuenta_contable_id:?int}|null
     */
    private function aplicarReglas(MovimientoParseado $m, $reglas): ?array
    {
        foreach ($reglas as $r) {
            if ($r->tipo === ConciliacionRegla::TIPO_CONCEPTO_REGEX || $r->tipo === ConciliacionRegla::TIPO_COMBINADA) {
                if (! $r->patron_concepto) {
                    continue;
                }
                if (! @preg_match('/'.$r->patron_concepto.'/u', $m->concepto)) {
                    continue;
                }
            }
            if ($r->tipo === ConciliacionRegla::TIPO_IMPORTE_EXACTO || $r->tipo === ConciliacionRegla::TIPO_COMBINADA) {
                $importe = $m->debito ?? $m->credito ?? 0;
                if ($r->patron_importe_desde !== null && $importe < (float) $r->patron_importe_desde) {
                    continue;
                }
                if ($r->patron_importe_hasta !== null && $importe > (float) $r->patron_importe_hasta) {
                    continue;
                }
            }

            return [
                'etiqueta' => $r->codigo,
                'cuenta_contable_id' => $r->cuenta_contable_id,
            ];
        }

        return null;
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
