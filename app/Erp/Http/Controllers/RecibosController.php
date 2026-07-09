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
     * v1.32 + v1.34 — Lista de clientes para el dropdown de recibos.
     * Sincroniza desde DistriApp on-demand (idempotente) + DEDUPLICA por CUIT.
     *
     * v1.34 fix: el import del Libro IVA Ventas crea auxiliares `CLI-{cuit}` y
     * el sync DistriApp crea `DA-CLI-{id}` — para el MISMO cliente real quedan
     * 2 filas con el mismo CUIT. Las facturas suelen estar en la `CLI-*`. Si el
     * operador elegía la `DA-CLI-*` no veía facturas. Ahora colapsamos por CUIT
     * y devolvemos como id canónico el auxiliar que TIENE facturas.
     */
    public function clientesParaRecibos(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        try {
            $this->distri->syncClientes($empresaId);
        } catch (\Throwable $e) {
            // Si DistriApp no responde, devolvemos lo que ya está en erp_auxiliares.
        }

        // Conteo de facturas imputables por auxiliar para elegir el canónico.
        $facturasPorAux = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->where('fv.empresa_id', $empresaId)
            ->whereIn('fv.estado', ['EMITIDA', 'COBRO_PARCIAL', 'CONTROLADA'])
            // FACTURA y NOTA_DEBITO: ambas son deuda del cliente (signo +1) e
            // imputables en un recibo. (Las NC, signo -1, van por su propio
            // listado que reduce el monto cobrable.)
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
            ->whereNull('fv.deleted_at')
            ->groupBy('fv.auxiliar_id')
            ->pluck(DB::raw('COUNT(*)'), 'fv.auxiliar_id')
            ->toArray();

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

        // Agrupar por CUIT (los sin CUIT quedan individuales por id).
        $grupos = [];
        foreach ($clientes as $c) {
            $clave = $c->cuit ? 'cuit:' . $c->cuit : 'id:' . $c->id;
            $grupos[$clave][] = $c;
        }

        $resultado = collect($grupos)->map(function ($filas) use ($facturasPorAux) {
            // Canónico = el que más facturas imputables tiene (sino el primero).
            usort($filas, function ($a, $b) use ($facturasPorAux) {
                return ($facturasPorAux[$b->id] ?? 0) <=> ($facturasPorAux[$a->id] ?? 0);
            });
            $canon = $filas[0];
            $totalFacturas = array_sum(array_map(fn ($f) => $facturasPorAux[$f->id] ?? 0, $filas));
            // Dirección: preferir la que tenga datos de DistriApp.
            $direccion = '';
            foreach ($filas as $f) {
                if (! empty($f->direccion_distriapp)) { $direccion = $f->direccion_distriapp; break; }
            }
            $partes = preg_split('/\r\n|\r|\n/', trim((string) $direccion), 2);
            return [
                'id' => $canon->id,
                'codigo' => $canon->codigo,
                'nombre' => $canon->nombre,
                'cuit' => $canon->cuit,
                'direccion_1' => $partes[0] ?? '',
                'direccion_2' => $partes[1] ?? '',
                'facturas_pendientes' => $totalFacturas, // hint para el frontend
                // ids hermanos (mismo CUIT) — el front no los usa, sirve para debug.
                'auxiliar_ids' => array_map(fn ($f) => $f->id, $filas),
            ];
        })->sortBy('nombre')->values();

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    /**
     * v1.32 + v1.34 — Facturas imputables (saldo > 0) de un cliente.
     *
     * v1.34 fix: matchea por CUIT. Resuelve el CUIT del auxiliar elegido y trae
     * facturas de TODOS los auxiliares que comparten ese CUIT (cubre el caso de
     * auxiliares duplicados CLI-* / DA-CLI-* del mismo cliente real).
     */
    public function facturasImputablesCliente(Request $request, int $clienteId): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        // Si el cliente está editando un borrador, el frontend pasa el id del
        // recibo para que saldoFactura excluya las imputaciones de ESE borrador
        // del cálculo. Sin eso, las facturas imputadas en el borrador aparecen
        // con saldo 0 y no se pueden re-agregar tras quitarlas del listado.
        $excludeReciboId = (int) $request->query('exclude_recibo_id', 0) ?: null;

        $auxIds = $this->auxiliaresHermanos($clienteId, $empresaId);

        $facturas = DB::table('erp_facturas_venta as fv')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'fv.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
            ->where('fv.empresa_id', $empresaId)
            ->whereIn('fv.auxiliar_id', $auxIds)
            ->whereIn('fv.estado', ['EMITIDA', 'COBRO_PARCIAL', 'CONTROLADA'])
            // FACTURA y NOTA_DEBITO: ambas son deuda del cliente (signo +1) e
            // imputables en un recibo. (Las NC, signo -1, van por su propio
            // listado que reduce el monto cobrable.)
            ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
            ->whereNull('fv.deleted_at')
            ->orderBy('fv.fecha_emision') // FIFO
            ->limit(500)
            ->get([
                'fv.id', 'fv.tipo_comprobante_id', 'tc.codigo_interno', 'tc.letra',
                'pv.numero as pv_numero', 'fv.numero', 'fv.fecha_emision',
                'fv.imp_total', 'fv.estado', 'fv.origen',
            ]);

        $resultado = $facturas->map(function ($f) use ($empresaId, $excludeReciboId) {
            $saldo = $this->svc->saldoFactura((int) $f->id, $empresaId, $excludeReciboId);
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

        return response()->json(['ok' => true, 'data' => $resultado, 'meta' => [
            'total' => $resultado->count(),
            'total_saldo' => round($resultado->sum('saldo'), 2),
            'auxiliares_consultados' => $auxIds,
        ]]);
    }

    /**
     * v1.34 — IDs de auxiliares que representan al mismo cliente real.
     * Si el auxiliar tiene CUIT, devuelve todos los que comparten ese CUIT
     * (tipo=Cliente). Si no, solo el id pasado.
     *
     * @return list<int>
     */
    private function auxiliaresHermanos(int $clienteId, int $empresaId): array
    {
        $cuit = DB::table('erp_auxiliares')
            ->where('id', $clienteId)->where('empresa_id', $empresaId)
            ->value('cuit');
        if (! $cuit) {
            return [$clienteId];
        }
        return DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'Cliente')
            ->where('cuit', $cuit)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * v1.32 — Próximo número PV-NRO sincronizado con DistriApp (sin reservar).
     * Útil para previsualización del form.
     */
    public function proximoNumero(Request $request): JsonResponse
    {
        $pv = (string) $request->query('pv', ReciboService::PV_DEFAULT);
        // AFIP transitó de PV 4 dígitos (histórico) a 5 dígitos. Aceptamos
        // ambos largos para tolerar comprobantes legados y los nuevos.
        if (! preg_match('/^\d{4,5}$/', $pv)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'PV_INVALIDO', 'message' => 'PV debe tener 4 o 5 dígitos',
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
            'cheques', // v1.32 — para mostrar el detalle del cheque en recibos emitidos
            'asiento:id,numero',
        ])->where('empresa_id', $empresaId)->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $recibo]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.crear');
        $data = $request->validate([
            'cliente_auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'fecha_emision' => ['nullable', 'date'], // ignorada: la emisión es hoy
            'fecha_cobro' => ['nullable', 'date', 'before_or_equal:today'],
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
            // "Otro" — medio/compensación especial (suma al cobro, va a 1.1.6.99).
            'otro_monto' => ['nullable', 'numeric', 'min:0'],
            'otro_observacion' => ['nullable', 'string', 'max:255'],
            // Redondeo — ajuste de cobranza (admite valores negativos).
            'redondeo_monto' => ['nullable', 'numeric'],
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

    /**
     * v1.32 — PATCH /tesoreria/recibos/{id}: actualiza un BORRADOR. Misma
     * validación que store(). El service rebota si el estado dejó de ser
     * BORRADOR (emitido/anulado).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'tesoreria.recibos.crear');
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $recibo = Recibo::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'cliente_auxiliar_id' => ['required', 'integer', 'exists:erp_auxiliares,id'],
            'fecha_emision' => ['nullable', 'date'], // ignorada: la emisión es hoy
            'fecha_cobro' => ['nullable', 'date', 'before_or_equal:today'],
            'detalle_cobro' => ['nullable', 'string', 'max:200'],
            'comprobantes_imputados' => ['required', 'array', 'min:1'],
            'comprobantes_imputados.*.factura_venta_id' => ['required', 'integer', 'exists:erp_facturas_venta,id'],
            'comprobantes_imputados.*.monto_imputado' => ['required', 'numeric', 'min:0.01'],
            'monto_cobrado' => ['nullable', 'numeric', 'min:0'],
            'medio_cobro_id' => ['nullable', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'nc_aplicadas' => ['nullable', 'array'],
            'nc_aplicadas.*.nc_factura_id' => ['required', 'integer'],
            'nc_aplicadas.*.monto_aplicado' => ['required', 'numeric', 'min:0.01'],
            'retencion_iva_total' => ['nullable', 'numeric', 'min:0'],
            'retencion_iibb_total' => ['nullable', 'numeric', 'min:0'],
            'retencion_ganancias_total' => ['nullable', 'numeric', 'min:0'],
            // "Otro" — medio/compensación especial (suma al cobro, va a 1.1.6.99).
            'otro_monto' => ['nullable', 'numeric', 'min:0'],
            'otro_observacion' => ['nullable', 'string', 'max:255'],
            // Redondeo — ajuste de cobranza (admite valores negativos).
            'redondeo_monto' => ['nullable', 'numeric'],
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
            $actualizado = $this->svc->actualizar($recibo, $data, $request->user(), $empresaId);
            return response()->json(['ok' => true, 'data' => $actualizado]);
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

        // Datos opcionales de los cheques (cuando el medio de cobro es
        // CHEQUES_CARTERA). Pueden ser varios en un mismo recibo. El service
        // exige que vengan si el medio lo requiere y que sumen el cobro.
        // Back-compat: se sigue aceptando `cheque` (objeto único).
        $validated = $request->validate([
            'cheques' => ['nullable', 'array', 'min:1'],
            'cheques.*.numero_cheque' => ['required', 'string', 'max:30'],
            'cheques.*.banco_emisor' => ['required', 'string', 'max:100'],
            'cheques.*.cuit_librador' => ['nullable', 'string', 'max:13'],
            'cheques.*.librador_nombre' => ['nullable', 'string', 'max:200'],
            'cheques.*.fecha_emision' => ['required', 'date'],
            'cheques.*.fecha_pago' => ['required', 'date', 'after_or_equal:cheques.*.fecha_emision'],
            'cheques.*.importe' => ['required', 'numeric', 'min:0.01'],
            'cheques.*.observaciones' => ['nullable', 'string', 'max:500'],
            'cheque' => ['nullable', 'array'],
            'cheque.numero_cheque' => ['required_with:cheque', 'string', 'max:30'],
            'cheque.banco_emisor' => ['required_with:cheque', 'string', 'max:100'],
            'cheque.cuit_librador' => ['nullable', 'string', 'max:13'],
            'cheque.librador_nombre' => ['nullable', 'string', 'max:200'],
            'cheque.fecha_emision' => ['required_with:cheque', 'date'],
            'cheque.fecha_pago' => ['required_with:cheque', 'date', 'after_or_equal:cheque.fecha_emision'],
            'cheque.importe' => ['required_with:cheque', 'numeric', 'min:0.01'],
            'cheque.observaciones' => ['nullable', 'string', 'max:500'],
        ]);
        $cheques = $validated['cheques']
            ?? (isset($validated['cheque']) ? [$validated['cheque']] : null);

        try {
            $asiento = $this->svc->emitir($recibo, $request->user(), $cheques);
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
     * Lista las NC del cliente con saldo imputable > 0 (para el form de recibo).
     * v1.34+: matchea por CUIT (auxiliares hermanos), igual que las facturas.
     */
    public function ncLibresCliente(Request $request, int $clienteId): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);
        $auxIds = $this->auxiliaresHermanos($clienteId, $empresaId);

        $ncs = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_puntos_venta as pv', 'pv.id', '=', 'f.punto_venta_id')
            ->where('f.empresa_id', $empresaId)
            ->whereIn('f.auxiliar_id', $auxIds)
            ->where('tc.clase', 'NOTA_CREDITO')
            ->whereNull('f.deleted_at')
            ->orderByDesc('f.fecha_emision')
            ->get(['f.id', 'tc.codigo_interno', 'tc.letra', 'pv.numero as pv_numero',
                'f.numero', 'f.fecha_emision', 'f.imp_total']);

        $resultado = $ncs->map(function ($nc) use ($empresaId) {
            $saldo = $this->svc->saldoImputableNc((int) $nc->id, $empresaId);
            return [
                'id' => $nc->id,
                'tipo' => $nc->codigo_interno . ($nc->letra ? ' ' . $nc->letra : ''),
                'numero' => $nc->numero,
                'numero_completo' => sprintf('%04d-%08d', (int) $nc->pv_numero, (int) $nc->numero),
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
