<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Arca\MisComprobantesRun;
use App\Erp\Models\Arca\PadronCache;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Models\VentasCompras\FacturaVentaCae;
use App\Erp\Services\ArcaGatewayClient;
use App\Erp\Services\ConstatacionService;
use App\Erp\Services\MisComprobantesService;
use App\Erp\Services\PadronService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fachada ARCA interna (SPEC 03 §6.6). El ERP valida permisos/negocio y
 * delega las llamadas a AFIP al microservicio `arca-gateway`.
 *
 *   POST  /padrones/consultar              RN-40 informativo
 *   POST  /padrones/refrescar/{cuit}       fuerza refresh del cache
 *   POST  /comprobantes/constatar          RN-42 sin factura persistida
 *   POST  /facturas-compra/{id}/constatar  constata CAE de factura persistida
 *   POST  /mis-comprobantes/ejecutar       RN-43 scraper manual
 *   GET   /mis-comprobantes/runs           historial
 *   GET   /puntos-venta/afip               sincroniza PV habilitados (WSFE)
 *
 * Emisión (fachada sobre erp_factura_venta_cae + cola):
 *   GET   /facturas-venta/{id}/emision-status
 *   GET   /facturas-venta/{id}/cae
 *   POST  /facturas-venta/{id}/reintentar-emision
 */
class ArcaController extends Controller
{
    public function __construct(
        private readonly ArcaGatewayClient $gateway,
        private readonly PadronService $padron,
        private readonly ConstatacionService $constatacion,
        private readonly MisComprobantesService $misComp,
    ) {}

    public function padronConsultar(Request $request): JsonResponse
    {
        $data = $request->validate(['cuit' => ['required', 'string', 'max:13']]);

        try {
            $cache = $this->padron->consultar($data['cuit']);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $cache]);
    }

    public function padronRefrescar(string $cuit): JsonResponse
    {
        try {
            $cache = $this->padron->refrescar($cuit);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $cache]);
    }

    public function comprobantesConstatar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', 'integer'],
            'pto_vta' => ['required', 'integer'],
            'numero' => ['required', 'integer'],
            'cuit_emisor' => ['required', 'string'],
            'cae' => ['required', 'string'],
            'fecha_cbte' => ['nullable', 'date'],
            'imp_total' => ['nullable', 'numeric'],
        ]);

        try {
            $res = $this->constatacion->constatar($data);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function constatarFactura(Request $request, int $id): JsonResponse
    {
        $factura = FacturaCompra::where('empresa_id', 1)->findOrFail($id);
        $bloqueante = $request->boolean('bloqueante', false);

        try {
            $factura = $this->constatacion->constatarFactura($factura, $bloqueante);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $factura]);
    }

    public function misComprobantesEjecutar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        try {
            $run = $this->misComp->ejecutar(
                $data['desde'] ?? null,
                $data['hasta'] ?? null,
                $request->user()
            );
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $run]);
    }

    public function misComprobantesRuns(Request $request): JsonResponse
    {
        $query = MisComprobantesRun::query()
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('iniciado_at');

        return response()->json(['ok' => true, 'data' => $query->paginate(50)]);
    }

    /**
     * GET /arca/estado — health del microservicio arca-gateway. Proxy a
     * /health/ready del gateway Python. Útil para que el frontend muestre si
     * AFIP está alcanzable antes de habilitar la emisión.
     */
    public function estado(): JsonResponse
    {
        try {
            $resp = $this->gateway->healthReady();
            return response()->json([
                'ok' => $resp->ok(),
                'data' => [
                    'gateway_status' => $resp->status(),
                    'gateway_body' => $resp->json() ?? $resp->body(),
                    'gateway_url' => config('services.arca.gateway_url'),
                ],
            ], $resp->ok() ? 200 : 502);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'GATEWAY_UNREACHABLE', 'message' => $e->getMessage()],
            ], 502);
        }
    }

    public function puntosVentaAfip(): JsonResponse
    {
        $response = $this->gateway->puntosVenta();
        if (! $response->ok()) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'GATEWAY_ERROR', 'message' => 'status '.$response->status()],
            ], 502);
        }

        $data = $response->json();
        $ptos = $data['puntos_venta'] ?? $data;

        $sincronizados = 0;
        DB::transaction(function () use ($ptos, &$sincronizados) {
            foreach ($ptos as $p) {
                $numero = (int) ($p['numero'] ?? $p['pv'] ?? 0);
                if (! $numero) {
                    continue;
                }
                $existe = DB::table('erp_puntos_venta')
                    ->where('empresa_id', 1)->where('numero', $numero)->exists();
                if ($existe) {
                    DB::table('erp_puntos_venta')
                        ->where('empresa_id', 1)->where('numero', $numero)
                        ->update([
                            'bloqueado' => $p['bloqueado'] ?? 0,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('erp_puntos_venta')->insert([
                        'empresa_id' => 1,
                        'numero' => $numero,
                        'nombre' => $p['nombre'] ?? 'PV '.str_pad((string) $numero, 5, '0', STR_PAD_LEFT),
                        'tipo_emision' => $p['tipo_emision'] ?? 'CAE',
                        'bloqueado' => $p['bloqueado'] ?? 0,
                        'activo' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $sincronizados++;
            }
        });

        return response()->json([
            'ok' => true,
            'data' => ['sincronizados' => $sincronizados, 'afip_respuesta' => $data],
        ]);
    }

    // ---------- Emisión (fachada sobre factura) ----------

    public function emisionStatus(int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);
        $cae = FacturaVentaCae::where('factura_venta_id', $factura->id)->first();
        $queue = DB::table('erp_factura_venta_emision_queue')
            ->where('factura_venta_id', $factura->id)
            ->latest('id')->first();

        return response()->json([
            'ok' => true,
            'data' => [
                'factura_id' => $factura->id,
                'factura_estado' => $factura->estado,
                'cae' => $factura->cae,
                'fecha_vto_cae' => $factura->fecha_vto_cae,
                'emision' => $cae,
                'cola' => $queue ? [
                    'estado' => $queue->estado,
                    'intento_actual' => $queue->intento_actual ?? null,
                    'proximo_intento_at' => $queue->proximo_intento_at ?? null,
                    'ultimo_error' => $queue->ultimo_error ?? null,
                ] : null,
            ],
        ]);
    }

    public function cae(int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);
        $cae = FacturaVentaCae::where('factura_venta_id', $factura->id)->first();
        if (! $cae) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIN_CAE']], 404);
        }

        return response()->json(['ok' => true, 'data' => $cae]);
    }

    public function reintentarEmision(int $id): JsonResponse
    {
        $factura = FacturaVenta::where('empresa_id', 1)->findOrFail($id);
        if ($factura->estado !== 'EMISION_FALLIDA') {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'ESTADO_INVALIDO', 'message' => 'Solo se reintenta desde EMISION_FALLIDA (actual: '.$factura->estado.')'],
            ], 409);
        }

        // Lo vuelve a encolar. El worker de emisión (SPEC 03 F6 / RN-39) lo toma.
        DB::table('erp_factura_venta_emision_queue')->insert([
            'factura_venta_id' => $factura->id,
            'estado' => 'PENDIENTE',
            'intento_actual' => 0,
            'max_intentos' => 3,
            'proximo_intento_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $factura->update(['estado' => 'PREPARADA']);

        return response()->json([
            'ok' => true,
            'data' => ['factura_id' => $factura->id, 'estado' => 'PREPARADA', 'encolada' => true],
        ], 202);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];

        return response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
