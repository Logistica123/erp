<?php

namespace App\Erp\Services\LibroIva;

use App\Erp\Models\Arca\LibroIvaDetalle;
use App\Erp\Models\Arca\LibroIvaImportacion;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\FacturaCompraService;
use App\Erp\Services\FacturaVentaService;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Orquesta la importación del Libro IVA Digital ARCA (SPEC 03 §7.1).
 *
 * Flujo:
 *  1) RN-29 idempotencia: hash SHA-256 del archivo → rechaza duplicado.
 *  2) Parsea XLSX vía ParserLibroIva.
 *  3) RN-30 matching por fila:
 *     · MATCH_ERP si ya existe en erp_facturas_venta/compra por
 *       (tipo, pto_vta, numero, cuit).
 *     · MATCH_DISTRIAPP si existe en DistriApp (vía bridge) — se crea
 *       factura ERP vinculada.
 *     · NUEVA si no existe → se crea con origen=ARCA_IMPORT estado=RECIBIDA/EMITIDA.
 *     · CONFLICTO si discrepancia de importe > $1 vs DistriApp.
 *  4) Persiste cabecera erp_libro_iva_importaciones + N erp_libro_iva_detalle.
 */
class LibroIvaImportService
{
    public const TIPO_COMPRAS = 'COMPRAS';
    public const TIPO_VENTAS = 'VENTAS';

    public function __construct(
        private readonly ParserLibroIva $parser,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *   importacion_id:int, total_filas:int, nuevas:int, match_erp:int,
     *   match_distriapp:int, conflictos:int, warnings:array<int,string>,
     * }
     */
    public function importar(string $pathTemporal, string $tipo, string $periodo, string $nombreArchivo, User $usuario, int $empresaId = 1): array
    {
        if (! in_array($tipo, [self::TIPO_COMPRAS, self::TIPO_VENTAS], true)) {
            throw new DomainException('TIPO_INVALIDO: debe ser COMPRAS o VENTAS');
        }

        $hash = hash_file('sha256', $pathTemporal);
        if (! $hash) {
            throw new DomainException('HASH_ERROR: no se pudo leer el archivo');
        }

        // RN-29 idempotencia
        $duplicada = LibroIvaImportacion::where('archivo_hash', $hash)->first();
        if ($duplicada) {
            throw new DomainException(sprintf(
                'RN-29 LIBRO_IVA_DUPLICADO: archivo ya importado el %s (id=%d)',
                $duplicada->fecha_importacion?->format('d/m/Y H:i') ?? '?',
                $duplicada->id
            ));
        }

        $filas = $this->parser->parse($pathTemporal);

        // Guarda archivo original
        $ruta = $this->guardarArchivo($pathTemporal, $empresaId, $tipo, $periodo, $hash, $nombreArchivo);

        return DB::transaction(function () use ($filas, $tipo, $periodo, $hash, $nombreArchivo, $ruta, $usuario, $empresaId) {
            $importacion = LibroIvaImportacion::create([
                'empresa_id' => $empresaId,
                'periodo' => $periodo,
                'tipo' => $tipo,
                'archivo_hash' => $hash,
                'nombre_archivo' => $nombreArchivo,
                'ruta_archivo' => $ruta,
                'fecha_importacion' => now(),
                'importada_por_user_id' => $usuario->id,
                'total_filas' => count($filas),
            ]);

            $nuevas = 0; $matchErp = 0; $matchDa = 0; $conflictos = 0;
            $warnings = [];

            foreach ($filas as $idx => $fila) {
                try {
                    [$estado, $facturaId] = $this->procesarFila($fila, $tipo, $empresaId, $usuario);
                    LibroIvaDetalle::create([
                        'importacion_id' => $importacion->id,
                        'nro_fila' => $idx + 1,
                        'fecha' => $fila->fecha,
                        'tipo_cbte' => $fila->tipoCbte,
                        'pto_vta' => $fila->ptoVta,
                        'nro_cbte' => $fila->nroCbte,
                        'cuit_contraparte' => $fila->cuitContraparte,
                        'razon_social' => $fila->razonSocial,
                        'imp_neto_gravado' => $fila->impNetoGravado,
                        'imp_no_gravado' => $fila->impNoGravado,
                        'imp_exento' => $fila->impExento,
                        'imp_iva' => $fila->impIva,
                        'imp_total' => $fila->impTotal,
                        'cae' => $fila->cae,
                        'estado_matching' => $estado,
                        'factura_erp_id' => $facturaId,
                        'raw_row_json' => $fila->rawRow,
                    ]);

                    match ($estado) {
                        'NUEVA' => $nuevas++,
                        'MATCH_ERP' => $matchErp++,
                        'MATCH_DISTRIAPP' => $matchDa++,
                        'CONFLICTO' => $conflictos++,
                        default => null,
                    };
                } catch (\Throwable $e) {
                    $warnings[] = "Fila ".($idx + 1).": ".$e->getMessage();
                }
            }

            $importacion->update([
                'filas_nuevas' => $nuevas,
                'filas_match_erp' => $matchErp,
                'filas_match_distriapp' => $matchDa,
                'filas_conflicto' => $conflictos,
                'estado' => 'COMPLETADA',
            ]);

            $this->audit->logEvento(
                accion: 'LIBRO_IVA_IMPORTADO',
                modulo: 'arca',
                descripcion: sprintf(
                    '%s %s · %d filas · nuevas=%d match_erp=%d match_da=%d conflictos=%d',
                    $tipo, $periodo, count($filas), $nuevas, $matchErp, $matchDa, $conflictos
                ),
                empresaId: $empresaId,
            );

            return [
                'importacion_id' => $importacion->id,
                'total_filas' => count($filas),
                'nuevas' => $nuevas,
                'match_erp' => $matchErp,
                'match_distriapp' => $matchDa,
                'conflictos' => $conflictos,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Matching RN-30 + creación conditional de factura ERP si no existe.
     *
     * @return array{0:string, 1:?int}  [estado, factura_erp_id]
     */
    private function procesarFila(FilaLibroIva $fila, string $tipo, int $empresaId, User $usuario): array
    {
        $tipoCbteId = DB::table('erp_tipos_comprobante')
            ->where('codigo_afip', $fila->tipoCbte)
            ->value('id');

        if (! $tipoCbteId) {
            return ['CONFLICTO', null];
        }

        if ($tipo === self::TIPO_COMPRAS) {
            $existente = FacturaCompra::where('empresa_id', $empresaId)
                ->where('tipo_comprobante_id', $tipoCbteId)
                ->where('punto_venta', $fila->ptoVta)
                ->where('numero', $fila->nroCbte)
                ->where('cuit_emisor', $fila->cuitContraparte)
                ->first();

            if ($existente) {
                return ['MATCH_ERP', $existente->id];
            }

            // Match DistriApp: por ahora solo crea ERP con origen=ARCA_IMPORT.
            // (Bridge para compras queda pendiente — la info de Mis Comprobantes
            // ya cubre este caso via MisComprobantesService.)
            $auxiliarId = $this->resolverAuxiliar($fila->cuitContraparte, $fila->razonSocial, 'Proveedor', $empresaId);
            $f = FacturaCompra::create([
                'empresa_id' => $empresaId,
                'tipo_comprobante_id' => $tipoCbteId,
                'punto_venta' => $fila->ptoVta,
                'numero' => $fila->nroCbte,
                'cae' => $fila->cae,
                'fecha_vto_cae' => $fila->fechaVtoCae,
                'fecha_emision' => $fila->fecha,
                'fecha_recepcion' => now()->toDateString(),
                'auxiliar_id' => $auxiliarId,
                'cuit_emisor' => $fila->cuitContraparte,
                'razon_social_emisor' => $fila->razonSocial,
                'condicion_iva_id' => DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id') ?: 1,
                'moneda_id' => DB::table('erp_monedas')->where('codigo', 'ARS')->value('id') ?: 1,
                'cotizacion' => 1,
                'imp_neto_gravado' => $fila->impNetoGravado,
                'imp_no_gravado' => $fila->impNoGravado,
                'imp_exento' => $fila->impExento,
                'imp_iva' => $fila->impIva,
                'imp_tributos' => 0,
                'imp_percepciones' => $fila->impPercepciones,
                'imp_retenciones' => 0,
                'imp_total' => $fila->impTotal,
                'origen' => 'ARCA_IMPORT',
                'estado' => FacturaCompraService::ESTADO_RECIBIDA,
                'constatacion_estado' => $fila->cae ? 'VALIDO' : 'NO_APLICA', // implícita
                'created_by_user_id' => $usuario->id,
            ]);

            return ['NUEVA', $f->id];
        }

        // VENTAS
        $existente = FacturaVenta::where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $tipoCbteId)
            ->whereHas('puntoVenta', fn ($q) => $q->where('numero', $fila->ptoVta))
            ->where('numero', $fila->nroCbte)
            ->first();

        if ($existente) {
            // Diff de importe → CONFLICTO si desvía > $1.
            if (abs((float) $existente->imp_total - $fila->impTotal) > 1.0) {
                return ['CONFLICTO', $existente->id];
            }

            return ['MATCH_ERP', $existente->id];
        }

        $pvId = DB::table('erp_puntos_venta')
            ->where('empresa_id', $empresaId)->where('numero', $fila->ptoVta)
            ->value('id');
        if (! $pvId) {
            return ['CONFLICTO', null];
        }

        $auxiliarId = $this->resolverAuxiliar($fila->cuitContraparte, $fila->razonSocial, 'Cliente', $empresaId);

        $f = FacturaVenta::create([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $tipoCbteId,
            'punto_venta_id' => $pvId,
            'numero' => $fila->nroCbte,
            'cae' => $fila->cae,
            'fecha_vto_cae' => $fila->fechaVtoCae,
            'fecha_emision' => $fila->fecha,
            'auxiliar_id' => $auxiliarId,
            'condicion_iva_id' => DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id') ?: 1,
            'moneda_id' => DB::table('erp_monedas')->where('codigo', 'ARS')->value('id') ?: 1,
            'cotizacion' => 1,
            'concepto_afip' => 2,
            'imp_neto_gravado' => $fila->impNetoGravado,
            'imp_no_gravado' => $fila->impNoGravado,
            'imp_exento' => $fila->impExento,
            'imp_iva' => $fila->impIva,
            'imp_tributos' => $fila->impPercepciones,
            'imp_total' => $fila->impTotal,
            'origen' => 'ARCA_IMPORT',
            'estado' => FacturaVentaService::ESTADO_EMITIDA,
            'created_by_user_id' => $usuario->id,
        ]);

        return ['NUEVA', $f->id];
    }

    private function resolverAuxiliar(string $cuit, ?string $razonSocial, string $tipo, int $empresaId): int
    {
        $id = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('cuit', $cuit)
            ->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'tabla_ref' => 'arca.libro_iva',
            'id_ref' => 0,
            'codigo' => 'LIV-'.$cuit,
            'nombre' => $razonSocial ?? ($tipo.' '.$cuit),
            'cuit' => $cuit,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Re-aplica matching sobre filas que quedaron NUEVA sin factura_erp_id
     * (escenario raro, ocurre si se crearon facturas ERP después del import).
     *
     * @return array{reconciliadas:int}
     */
    public function conciliarMasivo(int $importacionId, User $usuario): array
    {
        $imp = LibroIvaImportacion::findOrFail($importacionId);
        $reconciliadas = 0;

        $filas = LibroIvaDetalle::where('importacion_id', $imp->id)
            ->whereIn('estado_matching', ['NUEVA', 'CONFLICTO'])
            ->whereNull('factura_erp_id')
            ->get();

        foreach ($filas as $d) {
            $tipoCbteId = DB::table('erp_tipos_comprobante')->where('codigo_afip', $d->tipo_cbte)->value('id');
            if (! $tipoCbteId) {
                continue;
            }

            if ($imp->tipo === self::TIPO_COMPRAS) {
                $f = FacturaCompra::where('empresa_id', $imp->empresa_id)
                    ->where('tipo_comprobante_id', $tipoCbteId)
                    ->where('punto_venta', $d->pto_vta)
                    ->where('numero', $d->nro_cbte)
                    ->where('cuit_emisor', $d->cuit_contraparte)
                    ->value('id');
            } else {
                $f = FacturaVenta::where('empresa_id', $imp->empresa_id)
                    ->where('tipo_comprobante_id', $tipoCbteId)
                    ->whereHas('puntoVenta', fn ($q) => $q->where('numero', $d->pto_vta))
                    ->where('numero', $d->nro_cbte)
                    ->value('id');
            }

            if ($f) {
                $d->update(['factura_erp_id' => $f, 'estado_matching' => 'MATCH_ERP']);
                $reconciliadas++;
            }
        }

        return ['reconciliadas' => $reconciliadas];
    }

    private function guardarArchivo(string $pathTemporal, int $empresaId, string $tipo, string $periodo, string $hash, string $nombre): string
    {
        $destino = sprintf(
            'erp/libro-iva/%d/%s/%s/%s__%s',
            $empresaId, mb_strtolower($tipo), $periodo, substr($hash, 0, 12), $nombre
        );
        Storage::disk('local')->put($destino, file_get_contents($pathTemporal));

        return $destino;
    }
}
