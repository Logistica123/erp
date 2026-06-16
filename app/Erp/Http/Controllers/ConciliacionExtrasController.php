<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\AsientoService;
use App\Erp\Services\Conciliacion\EmparejarEspejosService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * v1.48 — endpoints de cierre de conciliación:
 *   Bloque D · catálogo de motivos de diferencia
 *   Bloque E · pendientes de facturar (anticipos a distribuidores)
 *   Bloque F · reporte conciliaciones con diferencia
 *   Bloque B/G · transferencias internas pendientes (emparejar / descartar)
 */
class ConciliacionExtrasController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmparejarEspejosService $espejos,
    ) {}

    /**
     * v1.48 Anexo A — anticipos otorgados pendientes de un auxiliar (saldo en
     * 1.1.5.01 con débito, vía el asiento del mov de adelanto, sin cancelar).
     */
    public function anticiposPendientes(Request $request, int $auxId): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $rows = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_movimientos_asiento as l', 'l.asiento_id', '=', 'm.asiento_id')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'l.cuenta_id')
            ->where('c.codigo', '1.1.5.01')
            ->where('l.auxiliar_id', $auxId)
            ->where('l.debe', '>', 0.005)
            ->whereNull('m.anticipo_cancelado_por_mov_id')
            ->whereNotNull('m.asiento_id')
            ->orderBy('m.fecha')
            ->get([
                'm.id as mov_id', 'm.fecha', 'l.debe as monto', 'm.concepto as glosa',
                DB::raw('DATEDIFF(CURDATE(), m.fecha) as dias_pendiente'),
            ]);
        $total = round((float) $rows->sum('monto'), 2);

        return response()->json(['ok' => true, 'data' => $rows, 'meta' => ['total' => $total]]);
    }

    /** Bloque D — catálogo de motivos para el dropdown. */
    public function motivos(Request $request): JsonResponse
    {
        $rows = DB::table('erp_conciliacion_motivos as m')
            ->leftJoin('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_ajuste_id')
            ->where('m.activo', 1)
            ->orderBy('m.orden_visual')
            ->get([
                'm.id', 'm.codigo', 'm.nombre', 'm.tipo', 'm.signo_esperado',
                'm.requiere_auxiliar_tipo', 'm.cuenta_ajuste_id', 'm.observaciones',
                'c.codigo as cuenta_codigo', 'c.nombre as cuenta_nombre',
            ]);

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /** Bloque E — lista de movimientos pendientes de facturar. */
    public function pendientesFacturar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');

        $q = $this->queryPendientesFacturar($request);
        $rows = $q->get();
        $total = round((float) $rows->sum('diferencia_a_facturar'), 2);

        // Totales por distribuidor.
        $porDistribuidor = $rows->groupBy('distribuidor')->map(fn ($g) => [
            'distribuidor' => $g->first()->distribuidor,
            'cantidad' => $g->count(),
            'total' => round((float) $g->sum('diferencia_a_facturar'), 2),
        ])->values();

        return response()->json(['ok' => true, 'data' => $rows, 'meta' => [
            'total_pendiente' => $total, 'por_distribuidor' => $porDistribuidor,
        ]]);
    }

    public function exportPendientesFacturar(Request $request): Response
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $rows = $this->queryPendientesFacturar($request)->get();

        $headers = ['Fecha pago', 'Distribuidor', 'CUIT', 'Monto pagado', 'Diferencia a facturar', 'Factura origen', 'Días pendiente', 'Observación'];
        $lineas = [implode(';', $headers)];
        foreach ($rows as $r) {
            $lineas[] = implode(';', [
                $r->fecha, $this->csv($r->distribuidor), $r->cuit ?? '',
                number_format((float) $r->monto_pagado, 2, ',', '.'),
                number_format((float) $r->diferencia_a_facturar, 2, ',', '.'),
                $this->csv($r->factura_origen ?? ''), $r->dias_pendiente,
                $this->csv($r->observaciones_pendiente ?? ''),
            ]);
        }
        $csv = "\xEF\xBB\xBF".implode("\r\n", $lineas);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pendientes_facturar.csv"',
        ]);
    }

    /** Bloque E — asociar NC complementaria (cancela el anticipo). */
    public function asociarNc(Request $request, int $movId): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate([
            'nc_factura_compra_id' => ['required', 'integer', 'exists:erp_facturas_compra,id'],
        ]);

        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($movId);
        if (! $mov->pendiente_factura_complementaria) {
            throw new DomainException('MOV_NO_PENDIENTE_FACTURAR');
        }
        $empresaId = $mov->cuentaBancaria->empresa_id;

        $nc = DB::table('erp_facturas_compra')->where('id', $data['nc_factura_compra_id'])
            ->where('empresa_id', $empresaId)->whereNull('deleted_at')->first();
        if (! $nc) throw new DomainException('NC_NO_ENCONTRADA');

        // Validaciones D-48: monto ≈ pendiente (tol $1) + mismo auxiliar.
        $pendiente = round((float) $mov->monto_pendiente_facturar, 2);
        if (abs((float) $nc->imp_total - $pendiente) > 1.0) {
            throw new DomainException(sprintf('NC_MONTO_NO_COINCIDE: NC $%.2f vs pendiente $%.2f', $nc->imp_total, $pendiente));
        }
        if ((int) $nc->auxiliar_id !== (int) $mov->distribuidor_pendiente_id) {
            throw new DomainException('NC_AUXILIAR_NO_COINCIDE');
        }

        $cuentaProv = DB::table('erp_auxiliares')->where('id', $mov->distribuidor_pendiente_id)->value('cuenta_contable_default_id')
            ?? DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', '2.1.1.01')->value('id');
        $cuentaAnticipo = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', '1.1.5.01')->value('id');
        if (! $cuentaProv || ! $cuentaAnticipo) throw new DomainException('CUENTAS_ANTICIPO_INEXISTENTES');

        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');

        DB::transaction(function () use ($mov, $nc, $pendiente, $cuentaProv, $cuentaAnticipo, $diarioId, $empresaId, $request) {
            DB::statement('SET @erp_current_user_id = ?', [$request->user()->id]);
            $glosa = "Cancelación anticipo por NC compra #{$nc->numero} (mov #{$mov->id})";
            $asientoSvc = app(AsientoService::class);
            $asiento = $asientoSvc->crearBorrador([
                'empresa_id' => $empresaId, 'diario_id' => $diarioId, 'fecha' => $nc->fecha_emision ?? $mov->fecha,
                'glosa' => $glosa, 'origen' => 'BANCO', 'origen_tabla' => 'erp_movimientos_bancarios', 'origen_id' => $mov->id,
                'usuario_id' => $request->user()->id,
                'movimientos' => [
                    ['cuenta_id' => (int) $cuentaProv, 'auxiliar_id' => (int) $mov->distribuidor_pendiente_id, 'debe' => $pendiente, 'haber' => 0, 'glosa' => $glosa],
                    ['cuenta_id' => (int) $cuentaAnticipo, 'auxiliar_id' => (int) $mov->distribuidor_pendiente_id, 'debe' => 0, 'haber' => $pendiente, 'glosa' => $glosa],
                ],
            ]);
            $asientoSvc->contabilizar($asiento);

            $mov->update([
                'nc_complementaria_id' => $nc->id,
                'pendiente_factura_complementaria' => 0,
            ]);
        });

        $this->audit->logEvento('PENDIENTE_FACTURAR_RESUELTO', 'tesoreria',
            "Mov #{$mov->id} asociado NC compra #{$nc->numero} por \${$pendiente}", $empresaId);

        return response()->json(['ok' => true, 'data' => $mov->fresh()]);
    }

    /** Bloque E — anular pendiente (con motivo). */
    public function anularPendiente(Request $request, int $movId): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        $mov = MovimientoBancario::with('cuentaBancaria')->findOrFail($movId);
        if (! $mov->pendiente_factura_complementaria) {
            throw new DomainException('MOV_NO_PENDIENTE_FACTURAR');
        }
        $mov->update([
            'pendiente_factura_complementaria' => 0,
            'observaciones_pendiente' => trim(($mov->observaciones_pendiente ?? '').' · ANULADO: '.$data['motivo']),
        ]);
        $this->audit->logEvento('PENDIENTE_FACTURAR_ANULADO', 'tesoreria',
            "Mov #{$mov->id} pendiente de facturar anulado: {$data['motivo']}", $mov->cuentaBancaria->empresa_id);

        return response()->json(['ok' => true, 'data' => $mov->fresh()]);
    }

    /** Bloque F — reporte de conciliaciones con diferencia. */
    public function conciliacionesConDiferencia(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'contabilidad.asientos.ver');
        $rows = $this->queryConDiferencia($request)->get();

        $porMotivo = $rows->groupBy('motivo')->map(fn ($g) => [
            'motivo' => $g->first()->motivo ?? '(sin motivo)',
            'cantidad' => $g->count(), 'total' => round((float) $g->sum('diferencia'), 2),
        ])->values();

        return response()->json(['ok' => true, 'data' => $rows, 'meta' => [
            'total_diferencia' => round((float) $rows->sum('diferencia'), 2),
            'por_motivo' => $porMotivo,
        ]]);
    }

    public function exportConDiferencia(Request $request): Response
    {
        $this->requierePermiso($request, 'contabilidad.asientos.ver');
        $rows = $this->queryConDiferencia($request)->get();
        $headers = ['Fecha', 'Banco', 'Concepto', 'Monto mov', 'Diferencia', 'Cuenta ajuste', 'Motivo', 'Tipo'];
        $lineas = [implode(';', $headers)];
        foreach ($rows as $r) {
            $lineas[] = implode(';', [
                $r->fecha, $this->csv($r->banco ?? ''), $this->csv($r->concepto ?? ''),
                number_format((float) $r->monto_mov, 2, ',', '.'),
                number_format((float) $r->diferencia, 2, ',', '.'),
                $this->csv($r->cuenta_ajuste ?? ''), $this->csv($r->motivo ?? ''), $r->tipo ?? '',
            ]);
        }
        $csv = "\xEF\xBB\xBF".implode("\r\n", $lineas);
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="conciliaciones_con_diferencia.csv"',
        ]);
    }

    /** Bloque B/G — transferencias internas pendientes de emparejar. */
    public function transferenciasInternasPendientes(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $rows = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->where('m.es_transferencia_interna', 1)
            ->where('m.estado', 'PENDIENTE_TRANSF_INTERNA')
            ->whereNull('m.mov_espejo_id')
            ->orderBy('m.fecha')
            ->get([
                'm.id', 'm.fecha', 'm.concepto', 'm.debito', 'm.credito',
                'm.cuenta_bancaria_id', 'cb.nombre as cuenta_nombre', 'cb.empresa_id',
            ]);

        // Para cada uno, candidatos de otras cuentas (mismo monto absoluto, ±3 días).
        $data = $rows->map(function ($m) {
            $monto = max((float) $m->debito, (float) $m->credito);
            $esDebito = (float) $m->debito > 0.005;
            $cands = DB::table('erp_movimientos_bancarios as e')
                ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'e.cuenta_bancaria_id')
                ->where('e.es_transferencia_interna', 1)
                ->where('e.estado', 'PENDIENTE_TRANSF_INTERNA')
                ->whereNull('e.mov_espejo_id')
                ->where('e.id', '!=', $m->id)
                ->where('e.cuenta_bancaria_id', '!=', $m->cuenta_bancaria_id)
                ->where('cb.empresa_id', $m->empresa_id)
                ->when($esDebito,
                    fn ($q) => $q->whereRaw('ABS(e.credito - ?) < 0.005', [$monto]),
                    fn ($q) => $q->whereRaw('ABS(e.debito - ?) < 0.005', [$monto]))
                ->whereRaw('ABS(DATEDIFF(e.fecha, ?)) <= 3', [$m->fecha])
                ->get(['e.id', 'e.fecha', 'e.concepto', 'e.debito', 'e.credito', 'cb.nombre as cuenta_nombre']);
            return [
                'id' => $m->id, 'fecha' => $m->fecha, 'concepto' => $m->concepto,
                'monto' => $monto, 'signo' => $esDebito ? 'DEBITO' : 'CREDITO',
                'cuenta_nombre' => $m->cuenta_nombre, 'candidatos' => $cands,
            ];
        });

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function emparejarTransferenciaInterna(Request $request, int $movId): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $data = $request->validate(['espejo_id' => ['required', 'integer']]);
        $this->espejos->emparejarManual($movId, $data['espejo_id'], $request->user()->id);
        $this->audit->logEvento('TRANSF_INTERNA_EMPAREJADA', 'tesoreria',
            "Mov #{$movId} emparejado manualmente con #{$data['espejo_id']}", null);
        return response()->json(['ok' => true]);
    }

    public function descartarTransferenciaInterna(Request $request, int $movId): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.conciliar');
        $this->espejos->descartarTransferenciaInterna($movId);
        $this->audit->logEvento('TRANSF_INTERNA_DESCARTADA', 'tesoreria',
            "Mov #{$movId} marcado como NO transferencia interna", null);
        return response()->json(['ok' => true]);
    }

    // ---- queries compartidas -------------------------------------------------

    private function queryPendientesFacturar(Request $request)
    {
        $q = DB::table('erp_movimientos_bancarios as m')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'm.distribuidor_pendiente_id')
            ->where('m.pendiente_factura_complementaria', 1)
            ->selectRaw("m.id, m.fecha, a.nombre as distribuidor, a.cuit,
                m.distribuidor_pendiente_id, GREATEST(m.debito, m.credito) as monto_pagado,
                m.monto_pendiente_facturar as diferencia_a_facturar, m.observaciones_pendiente,
                CONCAT(m.concepto) as factura_origen, DATEDIFF(CURDATE(), m.fecha) as dias_pendiente")
            ->orderBy('m.fecha');

        if ($d = $request->integer('distribuidor_id')) $q->where('m.distribuidor_pendiente_id', $d);
        if (($min = $request->input('monto_min')) !== null) $q->where('m.monto_pendiente_facturar', '>=', (float) $min);
        return $q;
    }

    private function queryConDiferencia(Request $request)
    {
        $q = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->leftJoin('erp_bancos as b', 'b.id', '=', 'cb.banco_id')
            ->leftJoin('erp_conciliacion_motivos as mo', 'mo.id', '=', 'm.motivo_diferencia_id')
            ->leftJoin('erp_cuentas_contables as ca', 'ca.id', '=', 'mo.cuenta_ajuste_id')
            ->whereNotNull('m.motivo_diferencia_id')
            ->selectRaw("m.id, m.fecha, b.nombre as banco, m.concepto,
                GREATEST(m.debito, m.credito) as monto_mov, m.monto_conciliado,
                (GREATEST(m.debito, m.credito) - m.monto_conciliado) as diferencia,
                CONCAT(ca.codigo, ' ', ca.nombre) as cuenta_ajuste, mo.nombre as motivo, mo.tipo")
            ->orderByDesc('m.fecha');

        if ($cb = $request->integer('banco_id')) $q->where('cb.banco_id', $cb);
        if ($mo = $request->integer('motivo_id')) $q->where('m.motivo_diferencia_id', $mo);
        if ($desde = $request->input('fecha_desde')) $q->whereDate('m.fecha', '>=', $desde);
        if ($hasta = $request->input('fecha_hasta')) $q->whereDate('m.fecha', '<=', $hasta);
        return $q;
    }

    private function csv(?string $s): string
    {
        return str_replace([';', "\n", "\r"], [',', ' ', ' '], (string) $s);
    }

    private function requierePermiso(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }
}
