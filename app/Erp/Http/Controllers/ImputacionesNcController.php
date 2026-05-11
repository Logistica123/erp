<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.15 Sprint O — imputación de Notas de Crédito a facturas/ND.
 *
 *   POST /api/erp/imputaciones-nc        crear (bulk)
 *   GET  /api/erp/imputaciones-nc        listar (filtra ?cliente_id=)
 *   DELETE /api/erp/imputaciones-nc/{id} revertir una imputación
 *
 * Permiso: tesoreria.nc.imputar.
 */
class ImputacionesNcController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $perfil = $user?->erpPerfil;
        if (! $perfil?->tienePermiso('tesoreria.nc.imputar')) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'SIN_PERMISO', 'message' => 'Necesitás el permiso tesoreria.nc.imputar.'],
            ], 403);
        }

        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
            'imputaciones' => ['required', 'array', 'min:1'],
            'imputaciones.*.nc_id' => ['required', 'integer'],
            'imputaciones.*.factura_id' => ['required', 'integer'],
            'imputaciones.*.importe' => ['required', 'numeric', 'gt:0'],
            'imputaciones.*.observaciones' => ['nullable', 'string', 'max:300'],
        ]);
        $empresaId = $perfil->empresa_id ?? 1;

        return DB::transaction(function () use ($data, $empresaId, $user) {
            $ids = [];
            $errores = [];

            foreach ($data['imputaciones'] as $idx => $imp) {
                // v1.15 Sprint O+ (D-TS-10): lock pesimista sobre NC y factura
                // dentro de la transacción. Revalidar saldos desde la BD —
                // serializa imputaciones concurrentes y previene over-imputation.
                $nc = DB::table('erp_facturas_venta as f')
                    ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                    ->where('f.id', $imp['nc_id'])
                    ->where('f.empresa_id', $empresaId)
                    ->where('f.auxiliar_id', $data['cliente_id'])
                    ->where('tc.clase', 'NOTA_CREDITO')
                    ->lockForUpdate()
                    ->select('f.id', 'f.imp_total', 'f.numero')
                    ->first();
                $factura = DB::table('erp_facturas_venta as f')
                    ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                    ->where('f.id', $imp['factura_id'])
                    ->where('f.empresa_id', $empresaId)
                    ->where('f.auxiliar_id', $data['cliente_id'])
                    ->whereIn('tc.clase', ['FACTURA', 'NOTA_DEBITO'])
                    ->lockForUpdate()
                    ->select('f.id', 'f.imp_total', 'f.numero')
                    ->first();

                if (! $nc || ! $factura) {
                    $errores[] = ['idx' => $idx, 'codigo' => 'NC_O_FACTURA_INVALIDA',
                        'detalle' => 'NC o factura no pertenecen al cliente / no son del tipo correcto.'];
                    continue;
                }

                // Saldos disponibles.
                $ncImputado = (float) DB::table('erp_imputaciones_nc')
                    ->where('nc_id', $nc->id)->sum('importe');
                $ncSaldo = (float) $nc->imp_total - $ncImputado;

                $facturaCobrado = (float) DB::table('erp_cobro_items as ci')
                    ->join('erp_cobros as co', 'co.id', '=', 'ci.cobro_id')
                    ->where('ci.factura_id', $factura->id)
                    ->whereNotIn('co.estado', ['ANULADO'])
                    ->sum('ci.importe');
                $facturaImputado = (float) DB::table('erp_imputaciones_nc')
                    ->where('factura_id', $factura->id)->sum('importe');
                $facturaSaldo = (float) $factura->imp_total - $facturaCobrado - $facturaImputado;

                $importe = (float) $imp['importe'];
                if ($importe > $ncSaldo + 0.005) {
                    $errores[] = ['idx' => $idx, 'codigo' => 'IMPORTE_EXCEDE_NC',
                        'detalle' => sprintf('importe %.2f > saldo NC %.2f', $importe, $ncSaldo)];
                    continue;
                }
                if ($importe > $facturaSaldo + 0.005) {
                    $errores[] = ['idx' => $idx, 'codigo' => 'IMPORTE_EXCEDE_FACTURA',
                        'detalle' => sprintf('importe %.2f > saldo factura %.2f', $importe, $facturaSaldo)];
                    continue;
                }

                $newId = DB::table('erp_imputaciones_nc')->insertGetId([
                    'empresa_id' => $empresaId,
                    'nc_id' => $nc->id,
                    'factura_id' => $factura->id,
                    'importe' => $importe,
                    'fecha_imputacion' => now()->toDateString(),
                    'imputado_por' => $user->id,
                    'imputado_at' => now(),
                    'observaciones' => $imp['observaciones'] ?? null,
                ]);
                $ids[] = $newId;
            }

            if (! empty($errores)) {
                throw new \DomainException('VALIDACION: '.json_encode($errores, JSON_UNESCAPED_UNICODE));
            }

            $this->audit->logEvento(
                accion: 'IMPUTACION_NC',
                modulo: 'tesoreria',
                descripcion: sprintf('Imputación de %d NC del cliente #%d (importes totales)', count($ids), $data['cliente_id']),
                empresaId: $empresaId,
            );

            return response()->json(['ok' => true, 'data' => ['ids' => $ids]], 201);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $empresaId = $request->user()?->erpPerfil?->empresa_id ?? 1;
        $clienteId = (int) $request->query('cliente_id', 0);

        $q = DB::table('erp_imputaciones_nc as i')
            ->join('erp_facturas_venta as nc', 'nc.id', '=', 'i.nc_id')
            ->join('erp_facturas_venta as fa', 'fa.id', '=', 'i.factura_id')
            ->where('i.empresa_id', $empresaId);

        if ($clienteId > 0) {
            $q->where('nc.auxiliar_id', $clienteId);
        }

        $rows = $q->select(
            'i.id', 'i.nc_id', 'i.factura_id', 'i.importe',
            'i.fecha_imputacion', 'i.imputado_at', 'i.observaciones',
            'nc.numero as nc_numero', 'nc.punto_venta as nc_pv',
            'fa.numero as factura_numero', 'fa.punto_venta as factura_pv',
        )->orderByDesc('i.fecha_imputacion')->limit(500)->get();

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $perfil = $user?->erpPerfil;
        if (! $perfil?->tienePermiso('tesoreria.nc.imputar')) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'SIN_PERMISO'],
            ], 403);
        }

        $empresaId = $perfil->empresa_id ?? 1;
        $imp = DB::table('erp_imputaciones_nc')->where('id', $id)->where('empresa_id', $empresaId)->first();
        if (! $imp) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA']], 404);
        }

        DB::table('erp_imputaciones_nc')->where('id', $id)->delete();

        $this->audit->logEvento(
            accion: 'IMPUTACION_NC_REVERTIDA',
            modulo: 'tesoreria',
            descripcion: sprintf('Reversa imputación NC #%d (importe %.2f)', $id, (float) $imp->importe),
            empresaId: $empresaId,
        );

        return response()->json(['ok' => true]);
    }
}
