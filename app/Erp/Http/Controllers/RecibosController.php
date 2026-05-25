<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\Recibo;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\Integracion\DistriAppBridge;
use App\Erp\Services\ReciboService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.31 — Endpoints de Recibos.
 *
 *   GET    /tesoreria/recibos                    listado con filtros
 *   GET    /tesoreria/recibos/{id}               detalle
 *   POST   /tesoreria/recibos                    crear BORRADOR
 *   POST   /tesoreria/recibos/{id}/emitir        BORRADOR → EMITIDO + asiento
 *   POST   /tesoreria/recibos/{id}/anular        EMITIDO → ANULADO con motivo
 *   POST   /tesoreria/recibos/auto-imputar-nc    helper (preview NC FIFO)
 *   GET    /clientes/{id}/notas-credito-libres   helper (NC con saldo imputable)
 */
class RecibosController
{
    public function __construct(
        private readonly ReciboService $svc,
        private readonly DistriAppBridge $distri, // v1.32
    ) {}

    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $q = Recibo::with(['cliente:id,nombre,cuit'])
            ->where('empresa_id', $empresaId);

        if ($cliente = (int) $request->query('cliente_id', 0)) {
            $q->where('cliente_auxiliar_id', $cliente);
        }
        if ($estado = (string) $request->query('estado', '')) {
            $q->where('estado', $estado);
        }
        if ($busqueda = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($busqueda) {
                $w->where('numero', 'like', "%{$busqueda}%")
                  ->orWhere('numero_correlativo', 'like', "%{$busqueda}%")
                  ->orWhereHas('cliente', fn ($qc) => $qc->where('nombre', 'like', "%{$busqueda}%"));
            });
        }
        if ($desde = (string) $request->query('desde', '')) {
            $q->where('fecha_emision', '>=', $desde);
        }
        if ($hasta = (string) $request->query('hasta', '')) {
            $q->where('fecha_emision', '<=', $hasta);
        }

        $rows = $q->orderByDesc('fecha_emision')->orderByDesc('id')->limit(500)->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * v1.32 — Lista de clientes para el dropdown de recibos.
     * Sincroniza desde DistriApp on-demand (idempotente).
     */
    public function clientesParaRecibos(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        // Sync on-demand (rápido, ~12 filas hoy).
        try {
            $this->distri->syncClientes($empresaId);
        } catch (\Throwable $e) {
            // Si DistriApp no responde, igual devolvemos lo que ya está en erp_auxiliares.
        }

        $clientes = DB::table('erp_auxiliares as a')
            ->leftJoin('basepersonal.clientes as bc', function ($j) {
                $j->on('bc.id', '=', 'a.id_ref')->where('a.tabla_ref', '=', 'basepersonal.clientes');
            })
            ->where('a.empresa_id', $empresaId)
            ->where('a.tipo', 'Cliente')
            ->where('a.activo', 1)
            ->orderBy('a.nombre')
            ->get([
                'a.id', 'a.codigo', 'a.nombre', 'a.cuit',
                'bc.direccion as direccion_distriapp',
            ]);

        $resultado = $clientes->map(function ($c) {
            $direccion = trim((string) ($c->direccion_distriapp ?? ''));
            $partes = preg_split('/\r\n|\r|\n/', $direccion, 2);
            return [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
                'cuit' => $c->cuit,
                'direccion_1' => $partes[0] ?? '',
                'direccion_2' => $partes[1] ?? '',
            ];
        });

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    /**
     * v1.32 — Facturas imputables (saldo > 0) de un cliente para sumar al recibo.
     */
    public function facturasImputablesCliente(Request $request, int $clienteId): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $facturas = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
            ->where('fv.empresa_id', $empresaId)
            ->where('fv.auxiliar_id', $clienteId)
            ->whereIn('fv.estado', ['EMITIDA', 'COBRO_PARCIAL', 'CONTROLADA'])
            ->where('tc.clase', 'FACTURA')
            ->whereNull('fv.deleted_at')
            ->orderByDesc('fv.fecha_emision')
            ->limit(500)
            ->get([
                'fv.id', 'fv.tipo_comprobante_id', 'tc.codigo_interno', 'tc.letra',
                'pv.numero as pv_numero', 'fv.numero', 'fv.fecha_emision',
                'fv.imp_total', 'fv.estado', 'fv.origen',
            ]);

        $resultado = $facturas->map(function ($f) use ($empresaId) {
            $saldo = $this->svc->saldoFactura((int) $f->id, $empresaId);
            return [
                'id' => $f->id,
                'tipo' => $f->codigo_interno . ($f->letra ? ' ' . $f->letra : ''),
                'numero_completo' => sprintf('%04d-%08d', (int) $f->pv_numero, (int) $f->numero),
                'fecha_emision' => $f->fecha_emision,
                'imp_total' => $f->imp_total,
                'saldo' => $saldo,
                'estado' => $f->estado,
                'origen' => $f->origen,
            ];
        })->filter(fn ($f) => $f['saldo'] > 0.01)->values();

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    /**
     * v1.32 — Próximo número PV-NRO sincronizado con DistriApp (sin reservar).
     * Útil para previsualización del form.
     */
    public function proximoNumero(Request $request): JsonResponse
    {
        $pv = (string) $request->query('pv', ReciboService::PV_DEFAULT);
        if (! preg_match('/^\d{4}$/', $pv)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'PV_INVALIDO', 'message' => 'PV debe tener 4 dígitos',
            ]], 422);
        }

        $maxLocal = (int) DB::table('erp_secuencias_recibo')
            ->where('punto_venta', $pv)->value('ultimo_numero');
        $maxDistriapp = $this->distri->ultimoNumeroRecibo($pv);
        $proximo = max($maxLocal, $maxDistriapp) + 1;

        return response()->json(['ok' => true, 'data' => [
            'punto_venta' => $pv,
            'numero' => str_pad((string) $proximo, 8, '0', STR_PAD_LEFT),
            'max_local' => $maxLocal,
            'max_distriapp' => $maxDistriapp,
            'consultado_distriapp' => $maxDistriapp > 0 || $maxLocal === 0,
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $recibo = Recibo::with([
            'cliente:id,nombre,cuit',
            'comprobantesImputados', // v1.32
            'ncAplicadas.nc:id,tipo_comprobante_id,numero,imp_total',
            'retenciones.cuentaContable:id,codigo,nombre',
            'medioCobro:id,nombre,banco_id',
            'asiento:id,numero',
        ])->where('empresa_id', $empresaId)->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $recibo]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.crear');
        $data = $request->validate([
            'cliente_auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'fecha_emision' => ['nullable', 'date'],
            'detalle_cobro' => ['nullable', 'string', 'max:200'],
            'comprobantes_imputados' => ['required', 'array', 'min:1'],
            'comprobantes_imputados.*.factura_venta_id' => ['required', 'integer', 'exists:erp_facturas_venta,id'],
            'comprobantes_imputados.*.monto_imputado' => ['required', 'numeric', 'min:0.01'],
            'monto_cobrado' => ['nullable', 'numeric', 'min:0'],
            'medio_cobro_id' => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'auto_imputar_nc' => ['nullable', 'boolean'],
            'nc_aplicadas' => ['nullable', 'array'],
            'nc_aplicadas.*.nc_factura_id' => ['required', 'integer'],
            'nc_aplicadas.*.monto_aplicado' => ['required', 'numeric', 'min:0.01'],
            // v1.32 — Retenciones simples (sumarias por tipo).
            'retencion_iva_total' => ['nullable', 'numeric', 'min:0'],
            'retencion_iibb_total' => ['nullable', 'numeric', 'min:0'],
            'retencion_ganancias_total' => ['nullable', 'numeric', 'min:0'],
            // v1.31 — Retenciones detalladas (opcional, coexisten).
            'retenciones' => ['nullable', 'array'],
            'retenciones.*.tipo' => ['required', 'in:GANANCIAS,IVA,IIBB,SUSS,OTRO'],
            'retenciones.*.jurisdiccion_codigo' => ['nullable', 'string', 'size:3'],
            'retenciones.*.numero_certificado' => ['nullable', 'string', 'max:40'],
            'retenciones.*.alicuota' => ['nullable', 'numeric'],
            'retenciones.*.base_imponible' => ['nullable', 'numeric'],
            'retenciones.*.monto' => ['required', 'numeric', 'min:0.01'],
            'retenciones.*.cuenta_contable_id' => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
        ]);

        try {
            $recibo = $this->svc->crear(
                $data,
                $request->user(),
                (int) ($request->header('X-Empresa-Id') ?: 1),
            );
            return response()->json(['ok' => true, 'data' => $recibo], 201);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function emitir(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.crear');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $recibo = Recibo::with(['ncAplicadas', 'retenciones'])
            ->where('empresa_id', $empresaId)->findOrFail($id);
        try {
            $asiento = $this->svc->emitir($recibo, $request->user());
            return response()->json(['ok' => true, 'data' => [
                'recibo_id' => $recibo->id,
                'asiento_id' => $asiento->id,
                'estado' => $recibo->fresh()->estado,
            ]]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.anular');
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $recibo = Recibo::with(['ncAplicadas'])
            ->where('empresa_id', $empresaId)->findOrFail($id);
        try {
            $this->svc->anular($recibo, $data['motivo'], $request->user());
            return response()->json(['ok' => true, 'data' => ['estado' => 'ANULADO']]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
    }

    /**
     * Helper para el form: dado un cliente y factura, devuelve la
     * pre-imputación FIFO de NC.
     */
    public function autoImputarNc(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.crear');
        $data = $request->validate([
            'factura_venta_id' => ['required', 'integer', 'exists:erp_facturas_venta,id'],
        ]);
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $factura = FacturaVenta::where('empresa_id', $empresaId)->findOrFail($data['factura_venta_id']);
        $aplicadas = $this->svc->autoImputarNcFifo($factura, $empresaId);

        // Enriquecer con datos de cada NC.
        $idsNc = array_column($aplicadas, 'nc_factura_id');
        $ncMap = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->whereIn('f.id', $idsNc)
            ->select('f.id', 'tc.codigo_interno', 'tc.letra', 'f.numero', 'f.fecha_emision', 'f.imp_total')
            ->get()->keyBy('id');
        $enriched = array_map(function ($a) use ($ncMap) {
            $nc = $ncMap[$a['nc_factura_id']] ?? null;
            return [
                ...$a,
                'nc' => $nc ? [
                    'id' => $nc->id,
                    'tipo' => $nc->codigo_interno . ($nc->letra ? ' ' . $nc->letra : ''),
                    'numero' => $nc->numero,
                    'fecha_emision' => $nc->fecha_emision,
                    'imp_total' => $nc->imp_total,
                ] : null,
            ];
        }, $aplicadas);

        return response()->json(['ok' => true, 'data' => [
            'nc_aplicadas' => $enriched,
            'total_nc' => array_sum(array_map(fn ($a) => (float) $a['monto_aplicado'], $aplicadas)),
        ]]);
    }

    /**
     * Lista las NC del cliente con saldo imputable > 0 (para el form manual).
     */
    public function ncLibresCliente(Request $request, int $clienteId): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $ncs = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $clienteId)
            ->where('tc.clase', 'NOTA_CREDITO')
            ->whereNull('f.deleted_at')
            ->orderByDesc('f.fecha_emision')
            ->get(['f.id', 'tc.codigo_interno', 'tc.letra', 'f.numero',
                'f.fecha_emision', 'f.imp_total']);

        $resultado = $ncs->map(function ($nc) use ($empresaId) {
            $saldo = $this->svc->saldoImputableNc((int) $nc->id, $empresaId);
            return [
                'id' => $nc->id,
                'tipo' => $nc->codigo_interno . ($nc->letra ? ' ' . $nc->letra : ''),
                'numero' => $nc->numero,
                'fecha_emision' => $nc->fecha_emision,
                'imp_total' => $nc->imp_total,
                'saldo_imputable' => $saldo,
            ];
        })->filter(fn ($n) => $n['saldo_imputable'] > 0.01)->values();

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => [
            'code' => $code, 'message' => $e->getMessage(),
        ]], 422);
    }
}
