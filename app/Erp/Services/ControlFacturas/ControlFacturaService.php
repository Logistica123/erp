<?php

namespace App\Erp\Services\ControlFacturas;

use App\Erp\Services\ConstatacionService;
use App\Erp\Services\PadronService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * v1.44 — Orquesta: PDF → extracción → WSCDC + APOC → persistencia + alertas.
 *
 * Reusa el `ConstatacionService` v1.28 (WSCDC vía arca-gateway) y el
 * `PadronService` v1.28 (APOC vía padrón). NO carga la factura al ERP —
 * el resultado queda como historial de control.
 */
class ControlFacturaService
{
    public function __construct(
        private readonly PdfFacturaExtractorService $extractor,
        private readonly ConstatacionService $constatacion,
        private readonly PadronService $padron,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sube el PDF, extrae datos, devuelve preview SIN consultar AFIP.
     * Permite que el operador edite los campos antes de gatillar la validación.
     *
     * @return array<string,mixed>
     */
    public function extraerPreview(UploadedFile $pdf, int $userId): array
    {
        $hash = hash_file('sha256', $pdf->getRealPath());
        $storagePath = $pdf->storeAs(
            'control-facturas/' . date('Y-m'),
            sprintf('%s_%s.pdf', $hash, Carbon::now()->format('YmdHis')),
            'local',
        );
        $fullPath = Storage::disk('local')->path($storagePath);

        $extr = $this->extractor->extraer($fullPath);

        // Aviso si el mismo hash ya fue validado antes.
        $previo = DB::table('erp_control_facturas_validaciones')
            ->where('archivo_hash_sha256', $hash)
            ->orderByDesc('created_at')
            ->first(['id', 'resultado_global', 'created_at']);

        return [
            'archivo' => [
                'nombre' => $pdf->getClientOriginalName(),
                'path' => $storagePath,
                'size' => $pdf->getSize(),
                'hash' => $hash,
            ],
            'extraccion' => $extr,
            'previo' => $previo,
        ];
    }

    /**
     * Valida los datos contra AFIP (WSCDC + APOC) y guarda en historial.
     * Los `campos` ya vienen revisados/editados por el operador en la UI.
     *
     * @param  array{
     *   archivo:array{nombre:string,path:string,size:int,hash:string},
     *   campos:array<string,mixed>,
     *   metodo_extraccion:string,
     *   qr_detectado:bool,
     *   ocr_aplicado:bool,
     * }  $payload
     */
    public function validar(array $payload, int $userId): int
    {
        $campos = $payload['campos'];
        $faltantes = $this->camposFaltantes($campos);
        if ($faltantes) {
            return $this->persistirNoProcesable($payload, $userId, "Campos faltantes: " . implode(',', $faltantes));
        }

        // -- WSCDC (vía ConstatacionService existente) --
        $wscdcResult = ['resultado' => 'ERROR', 'datos_afip' => null, 'raw' => null, 'obs' => null];
        try {
            $r = $this->constatacion->constatar([
                'tipo' => (int) $campos['tipo_comprobante'],
                'pto_vta' => (int) $campos['punto_venta'],
                'numero' => (int) $campos['numero'],
                'cuit_emisor' => (string) $campos['cuit_emisor'],
                'cae' => (string) $campos['cae'],
                'fecha_cbte' => $campos['fecha_emision'],
                'imp_total' => $campos['importe_total'],
            ]);
            // Mapeo: VALIDO → A, INVALIDO → R, NO_ENCONTRADO → R, ERROR → ERROR
            $wscdcResult['resultado'] = match ($r['resultado']) {
                'VALIDO' => 'A',
                'INVALIDO', 'NO_ENCONTRADO' => 'R',
                default => 'ERROR',
            };
            $wscdcResult['datos_afip'] = $r['datos_afip'] ?? null;
            $wscdcResult['raw'] = $r['raw'] ?? null;
            $wscdcResult['obs'] = $r['raw']['observaciones'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('v1.44.wscdc.error', ['err' => $e->getMessage()]);
            $wscdcResult['obs'] = $e->getMessage();
        }

        // -- APOC (vía PadronService existente) --
        $apocResult = ['estado' => 'ERROR', 'motivo' => null];
        try {
            $cache = $this->padron->consultar((string) $campos['cuit_emisor']);
            $estadoCuit = strtoupper((string) ($cache->estado_cuit ?? ''));
            if ($estadoCuit === 'ACTIVO') {
                $apocResult['estado'] = 'NO_APOC';
            } else {
                $apocResult['estado'] = 'EN_APOC';
                $apocResult['motivo'] = "estado_cuit=" . ($estadoCuit ?: 'DESCONOCIDO');
            }
        } catch (\Throwable $e) {
            Log::warning('v1.44.apoc.error', ['err' => $e->getMessage()]);
            $apocResult['motivo'] = $e->getMessage();
        }

        // -- Resultado consolidado --
        $resultado = $this->consolidar($wscdcResult['resultado'], $apocResult['estado']);
        $confianza = $this->calcularConfianza($payload['metodo_extraccion'], $wscdcResult['resultado'], $apocResult['estado']);

        $id = DB::transaction(function () use ($payload, $userId, $campos, $wscdcResult, $apocResult, $resultado, $confianza) {
            DB::statement('SET @erp_current_user_id = ?', [$userId]);

            $vid = DB::table('erp_control_facturas_validaciones')->insertGetId([
                'empresa_id' => 1,
                'archivo_nombre' => $payload['archivo']['nombre'],
                'archivo_path' => $payload['archivo']['path'],
                'archivo_size_bytes' => (int) $payload['archivo']['size'],
                'archivo_hash_sha256' => $payload['archivo']['hash'],
                'metodo_extraccion' => $payload['metodo_extraccion'],
                'qr_detectado' => (bool) ($payload['qr_detectado'] ?? false),
                'ocr_aplicado' => (bool) ($payload['ocr_aplicado'] ?? false),
                'datos_extraidos' => json_encode($campos, JSON_UNESCAPED_UNICODE),
                'wscdc_consultado' => $wscdcResult['resultado'] !== 'ERROR' || $wscdcResult['obs'] !== null,
                'wscdc_resultado' => $wscdcResult['resultado'],
                'wscdc_obs' => is_array($wscdcResult['obs']) ? implode(' · ', $wscdcResult['obs']) : $wscdcResult['obs'],
                'wscdc_response_raw' => $wscdcResult['raw'] ? json_encode($wscdcResult['raw'], JSON_UNESCAPED_UNICODE) : null,
                'wscdc_fecha_consulta' => now(),
                'apoc_consultado' => $apocResult['estado'] !== 'ERROR' || $apocResult['motivo'] !== null,
                'apoc_estado' => $apocResult['estado'],
                'apoc_motivo' => $apocResult['motivo'],
                'apoc_fecha_consulta' => now(),
                'resultado_global' => $resultado,
                'nivel_confianza' => $confianza,
                'validado_por_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->crearAlertasSiCorresponde($vid, $resultado, $apocResult['estado']);
            return $vid;
        });

        $this->audit->logEvento(
            accion: 'CTLF_VALIDADA',
            modulo: 'control_facturas',
            descripcion: sprintf('Validación #%d: CUIT %s PV %d Nro %d CAE %s → %s/%s',
                $id, $campos['cuit_emisor'], $campos['punto_venta'], $campos['numero'],
                $campos['cae'], $resultado, $confianza),
        );
        return $id;
    }

    public function actualizarSeguimiento(int $vid, string $estado, ?string $obs, int $userId): void
    {
        if (! in_array($estado, ['PENDIENTE_REVISION', 'REVISADA_OK', 'REVISADA_DESCARTADA', 'ESCALADA'], true)) {
            throw new DomainException("ESTADO_SEGUIMIENTO_INVALIDO: {$estado}");
        }
        DB::table('erp_control_facturas_validaciones')->where('id', $vid)->update([
            'estado_seguimiento' => $estado,
            'observaciones_operador' => $obs,
            'fecha_revision' => now(),
            'revisada_por_user_id' => $userId,
            'updated_at' => now(),
        ]);
        $this->audit->logEvento(
            accion: 'CTLF_SEGUIMIENTO',
            modulo: 'control_facturas',
            descripcion: "Validación #{$vid} → {$estado} (user {$userId})",
        );
    }

    public function marcarAlertaLeida(int $alertaId): void
    {
        DB::table('erp_control_facturas_alertas')->where('id', $alertaId)->update(['leida' => true]);
    }

    // -- helpers privados ----------------------------------------------------

    private function persistirNoProcesable(array $payload, int $userId, string $motivo): int
    {
        $id = DB::table('erp_control_facturas_validaciones')->insertGetId([
            'empresa_id' => 1,
            'archivo_nombre' => $payload['archivo']['nombre'],
            'archivo_path' => $payload['archivo']['path'],
            'archivo_size_bytes' => (int) $payload['archivo']['size'],
            'archivo_hash_sha256' => $payload['archivo']['hash'],
            'metodo_extraccion' => $payload['metodo_extraccion'] === 'FALLO' ? 'FALLO' : $payload['metodo_extraccion'],
            'qr_detectado' => (bool) ($payload['qr_detectado'] ?? false),
            'ocr_aplicado' => (bool) ($payload['ocr_aplicado'] ?? false),
            'datos_extraidos' => json_encode($payload['campos'] ?? [], JSON_UNESCAPED_UNICODE),
            'resultado_global' => 'NO_PROCESABLE',
            'nivel_confianza' => 'BAJO',
            'validado_por_user_id' => $userId,
            'wscdc_obs' => $motivo,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    /** @return list<string> */
    private function camposFaltantes(array $campos): array
    {
        $criticos = ['cuit_emisor', 'tipo_comprobante', 'punto_venta', 'numero', 'cae', 'fecha_emision', 'importe_total'];
        return array_values(array_filter($criticos, fn ($k) => empty($campos[$k])));
    }

    private function consolidar(string $wscdc, string $apoc): string
    {
        if ($apoc === 'EN_APOC') return 'APOCRIFA';
        if ($wscdc === 'A' && in_array($apoc, ['NO_APOC', 'ERROR'], true)) return 'VALIDA';
        if ($wscdc === 'R') return 'INVALIDA';
        return 'ERROR';
    }

    private function calcularConfianza(string $metodo, string $wscdc, string $apoc): string
    {
        if ($metodo === 'QR' && $wscdc === 'A' && $apoc === 'NO_APOC') return 'ALTO';
        if ($wscdc === 'A') return 'MEDIO';
        return 'BAJO';
    }

    private function crearAlertasSiCorresponde(int $vid, string $resultado, string $apoc): void
    {
        if ($resultado === 'INVALIDA') {
            DB::table('erp_control_facturas_alertas')->insert([
                'validacion_id' => $vid,
                'tipo_alerta' => 'FACTURA_INVALIDA',
                'severidad' => 'ALTA',
                'mensaje' => 'WSCDC rechazó el comprobante. Datos del PDF no coinciden con AFIP.',
                'created_at' => now(),
            ]);
        }
        if ($apoc === 'EN_APOC') {
            DB::table('erp_control_facturas_alertas')->insert([
                'validacion_id' => $vid,
                'tipo_alerta' => 'CUIT_APOC',
                'severidad' => 'CRITICA',
                'mensaje' => 'CUIT emisor aparece en padrón APOC (apócrifo). No pagar/aceptar la factura sin verificar.',
                'created_at' => now(),
            ]);
        }
    }
}
