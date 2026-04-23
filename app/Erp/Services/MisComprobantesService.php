<?php

namespace App\Erp\Services;

use App\Erp\Models\Arca\MisComprobantesRun;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingesta automática desde "Mis Comprobantes" de ARCA (SPEC 03 RN-43).
 *
 * Invoca al scraper via arca-gateway. El gateway autentica con Clave Fiscal
 * delegada, resuelve captcha y parsea la tabla de recibidos/emitidos para
 * el rango solicitado. El ERP toma el payload y lo ingesta:
 *
 *   · Si la factura (tipo, pto_vta, numero, cuit_emisor) ya existe → skip.
 *   · Si no existe → crea FacturaCompra origen=MIS_COMPROBANTES estado=RECIBIDA.
 *
 * El run se registra en erp_mis_comprobantes_runs con estado y diff_json.
 * Si el scraper falla (login, captcha, HTML), queda registrado y el ERP
 * alerta al usuario para caer al import de Excel Libro IVA como fallback.
 */
class MisComprobantesService
{
    public function __construct(
        private readonly ArcaGatewayClient $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ejecuta un run manual sobre el rango [desde, hasta].
     * Si no se pasa rango, usa "ayer" → "hoy" (cubre el día completo anterior).
     */
    public function ejecutar(?string $desde, ?string $hasta, User $usuario, int $empresaId = 1): MisComprobantesRun
    {
        $desde = $desde ?? Carbon::yesterday()->toDateString();
        $hasta = $hasta ?? Carbon::today()->toDateString();
        $periodo = Carbon::parse($desde)->format('Y-m');

        $run = MisComprobantesRun::create([
            'empresa_id' => $empresaId,
            'periodo' => $periodo,
            'tipo' => 'RECIBIDOS',
            'estado' => 'EN_CURSO',
            'iniciado_at' => now(),
            'rango_desde' => $desde,
            'rango_hasta' => $hasta,
            'disparado_por_user_id' => $usuario->id,
        ]);

        try {
            $response = $this->gateway->misComprobantesRecibidos($desde, $hasta);
            if (! $response->ok()) {
                $this->marcarError($run, 'ERROR_HTML', "gateway status {$response->status()}: ".substr((string) $response->body(), 0, 300));

                return $run->fresh();
            }

            $data = $response->json();
            $comprobantes = $data['comprobantes'] ?? [];
            $totalRows = count($comprobantes);

            $nuevos = 0;
            $existentes = 0;
            $diff = [];

            foreach ($comprobantes as $c) {
                $exists = FacturaCompra::where('empresa_id', $empresaId)
                    ->where('tipo_comprobante_id', $this->resolverTipoCbteId((int) ($c['tipo_cbte'] ?? 0)))
                    ->where('punto_venta', (int) ($c['pto_vta'] ?? 0))
                    ->where('numero', (int) ($c['numero'] ?? 0))
                    ->where('cuit_emisor', preg_replace('/[^0-9]/', '', (string) ($c['cuit_emisor'] ?? '')))
                    ->exists();

                if ($exists) {
                    $existentes++;

                    continue;
                }

                try {
                    $f = $this->crearFacturaCompraDesdeMC($c, $empresaId, $usuario);
                    $nuevos++;
                    $diff[] = ['accion' => 'creada', 'factura_id' => $f->id, 'numero' => $f->numero];
                } catch (\Throwable $e) {
                    $diff[] = ['accion' => 'error', 'tipo_cbte' => $c['tipo_cbte'] ?? null, 'nro' => $c['numero'] ?? null, 'error' => $e->getMessage()];
                }
            }

            $run->update([
                'estado' => 'OK',
                'finalizado_at' => now(),
                'total_rows' => $totalRows,
                'nuevos' => $nuevos,
                'existentes' => $existentes,
                'diff_json' => $diff,
            ]);

            $this->audit->logEvento(
                accion: 'MIS_COMPROBANTES_RUN',
                modulo: 'arca',
                descripcion: sprintf(
                    'Run %s → %s · total %d · nuevos %d · ya existían %d',
                    $desde, $hasta, $totalRows, $nuevos, $existentes
                ),
                empresaId: $empresaId,
            );
        } catch (\Throwable $e) {
            $this->marcarError($run, 'ERROR_HTML', $e->getMessage());
        }

        return $run->fresh();
    }

    private function marcarError(MisComprobantesRun $run, string $tipoError, string $mensaje): void
    {
        $run->update([
            'estado' => $tipoError,
            'finalizado_at' => now(),
            'error_mensaje' => substr($mensaje, 0, 1000),
        ]);

        $this->audit->logEvento(
            accion: 'MIS_COMPROBANTES_ERROR',
            modulo: 'arca',
            descripcion: sprintf('Run %d %s: %s', $run->id, $tipoError, substr($mensaje, 0, 200)),
            empresaId: $run->empresa_id,
        );
    }

    private function resolverTipoCbteId(int $codigoAfip): ?int
    {
        if (! $codigoAfip) {
            return null;
        }

        return (int) DB::table('erp_tipos_comprobante')
            ->where('codigo_afip', $codigoAfip)
            ->value('id') ?: null;
    }

    private function crearFacturaCompraDesdeMC(array $c, int $empresaId, User $usuario): FacturaCompra
    {
        $cuitEmisor = preg_replace('/[^0-9]/', '', (string) ($c['cuit_emisor'] ?? ''));
        if (strlen($cuitEmisor) !== 11) {
            throw new DomainException('CUIT_EMISOR_INVALIDO: '.$cuitEmisor);
        }

        $tipoCbteId = $this->resolverTipoCbteId((int) ($c['tipo_cbte'] ?? 0));
        if (! $tipoCbteId) {
            throw new DomainException('TIPO_CBTE_DESCONOCIDO: '.($c['tipo_cbte'] ?? '?'));
        }

        // Auxiliar: buscar por CUIT; si no existe, crear con tipo Proveedor.
        $auxId = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('cuit', $cuitEmisor)
            ->value('id');
        if (! $auxId) {
            $auxId = DB::table('erp_auxiliares')->insertGetId([
                'empresa_id' => $empresaId,
                'tipo' => 'Proveedor',
                'tabla_ref' => 'arca.mis_comprobantes',
                'id_ref' => 0,
                'codigo' => 'MC-'.$cuitEmisor,
                'nombre' => $c['razon_social'] ?? 'Proveedor '.$cuitEmisor,
                'cuit' => $cuitEmisor,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return FacturaCompra::create([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $tipoCbteId,
            'punto_venta' => (int) $c['pto_vta'],
            'numero' => (int) $c['numero'],
            'cae' => $c['cae'] ?? null,
            'fecha_vto_cae' => $c['cae_vto'] ?? null,
            'fecha_emision' => $c['fecha'] ?? now()->toDateString(),
            'fecha_recepcion' => now()->toDateString(),
            'auxiliar_id' => $auxId,
            'cuit_emisor' => $cuitEmisor,
            'razon_social_emisor' => $c['razon_social'] ?? null,
            'condicion_iva_id' => DB::table('erp_condiciones_iva')->where('codigo_interno', 'RI')->value('id') ?: 1,
            'moneda_id' => DB::table('erp_monedas')->where('codigo', 'ARS')->value('id') ?: 1,
            'cotizacion' => 1,
            'imp_neto_gravado' => $c['imp_neto'] ?? 0,
            'imp_no_gravado' => $c['imp_no_grav'] ?? 0,
            'imp_exento' => $c['imp_exento'] ?? 0,
            'imp_iva' => $c['imp_iva'] ?? 0,
            'imp_tributos' => $c['imp_tributos'] ?? 0,
            'imp_percepciones' => 0,
            'imp_retenciones' => 0,
            'imp_total' => $c['imp_total'] ?? 0,
            'origen' => 'MIS_COMPROBANTES',
            'estado' => FacturaCompraService::ESTADO_RECIBIDA,
            'constatacion_estado' => $c['cae'] ? 'VALIDO' : 'NO_APLICA', // implícita según RN-43
            'created_by_user_id' => $usuario->id,
        ]);
    }
}
