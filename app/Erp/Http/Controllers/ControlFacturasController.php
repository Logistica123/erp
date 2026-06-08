<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\ControlFacturas\ControlFacturaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v1.44 — Endpoints del módulo de control de facturas.
 *
 *   POST   /api/erp/control-facturas/extraer        — sube PDF + extrae
 *   POST   /api/erp/control-facturas/validar        — valida contra AFIP
 *   GET    /api/erp/control-facturas                — historial
 *   GET    /api/erp/control-facturas/{id}           — detalle
 *   PATCH  /api/erp/control-facturas/{id}/seguimiento
 *   DELETE /api/erp/control-facturas/{id}           — solo admin
 *   GET    /api/erp/control-facturas/alertas
 *   PATCH  /api/erp/control-facturas/alertas/{id}/leer
 */
class ControlFacturasController
{
    public function __construct(private readonly ControlFacturaService $service) {}

    public function extraer(Request $request): JsonResponse
    {
        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],  // 10MB
        ]);
        try {
            $r = $this->service->extraerPreview($request->file('pdf'), $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $r]);
    }

    public function validar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'archivo.nombre' => ['required', 'string'],
            'archivo.path' => ['required', 'string'],
            'archivo.size' => ['required', 'integer'],
            'archivo.hash' => ['required', 'string', 'size:64'],
            'metodo_extraccion' => ['required', 'in:QR,OCR,MIXTO,FALLO'],
            'qr_detectado' => ['nullable', 'boolean'],
            'ocr_aplicado' => ['nullable', 'boolean'],
            'campos' => ['required', 'array'],
            'campos.cuit_emisor' => ['required', 'string', 'size:11'],
            'campos.cuit_receptor' => ['nullable', 'string', 'size:11'],
            'campos.tipo_comprobante' => ['required', 'integer'],
            'campos.punto_venta' => ['required', 'integer'],
            'campos.numero' => ['required', 'integer'],
            'campos.fecha_emision' => ['required', 'date'],
            'campos.importe_total' => ['required', 'numeric'],
            'campos.cae' => ['required', 'string', 'size:14'],
            'campos.moneda' => ['nullable', 'string'],
            'campos.tipo_doc_receptor' => ['nullable', 'integer'],
        ]);
        try {
            $id = $this->service->validar($data, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        $row = DB::table('erp_control_facturas_validaciones')->where('id', $id)->first();
        return response()->json(['ok' => true, 'data' => $row], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'resultado' => ['nullable', 'in:VALIDA,INVALIDA,APOCRIFA,ERROR,NO_PROCESABLE'],
            'seguimiento' => ['nullable', 'in:PENDIENTE_REVISION,REVISADA_OK,REVISADA_DESCARTADA,ESCALADA'],
            'cuit' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $q = DB::table('erp_control_facturas_validaciones as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.validado_por_user_id')
            ->orderByDesc('v.created_at')
            ->select([
                'v.id', 'v.archivo_nombre', 'v.metodo_extraccion', 'v.resultado_global',
                'v.nivel_confianza', 'v.estado_seguimiento', 'v.wscdc_resultado', 'v.apoc_estado',
                'v.datos_extraidos', 'v.created_at', 'u.name as validado_por_nombre',
            ]);
        if (! empty($filtros['desde'])) $q->where('v.created_at', '>=', $filtros['desde'] . ' 00:00:00');
        if (! empty($filtros['hasta'])) $q->where('v.created_at', '<=', $filtros['hasta'] . ' 23:59:59');
        if (! empty($filtros['resultado'])) $q->where('v.resultado_global', $filtros['resultado']);
        if (! empty($filtros['seguimiento'])) $q->where('v.estado_seguimiento', $filtros['seguimiento']);
        if (! empty($filtros['user_id'])) $q->where('v.validado_por_user_id', (int) $filtros['user_id']);
        if (! empty($filtros['cuit'])) {
            $cuit = preg_replace('/[^0-9]/', '', $filtros['cuit']);
            $q->where('v.datos_extraidos', 'like', "%\"cuit_emisor\":\"{$cuit}\"%");
        }

        return response()->json(['ok' => true, 'data' => $q->paginate(50)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = DB::table('erp_control_facturas_validaciones as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.validado_por_user_id')
            ->leftJoin('users as r', 'r.id', '=', 'v.revisada_por_user_id')
            ->where('v.id', $id)
            ->select(['v.*', 'u.name as validado_por_nombre', 'r.name as revisada_por_nombre'])
            ->first();
        if (! $row) abort(404);
        $alertas = DB::table('erp_control_facturas_alertas')
            ->where('validacion_id', $id)->orderByDesc('id')->get();
        return response()->json(['ok' => true, 'data' => ['validacion' => $row, 'alertas' => $alertas]]);
    }

    public function actualizarSeguimiento(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'estado_seguimiento' => ['required', 'in:PENDIENTE_REVISION,REVISADA_OK,REVISADA_DESCARTADA,ESCALADA'],
            'observaciones_operador' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $this->service->actualizarSeguimiento($id, $data['estado_seguimiento'], $data['observaciones_operador'] ?? null, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // permiso control_facturas.admin verificado por middleware (o por chequeo manual abajo).
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso('control_facturas.admin')) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => 'Falta permiso control_facturas.admin']], 403);
        }
        $row = DB::table('erp_control_facturas_validaciones')->where('id', $id)->first();
        if (! $row) abort(404);
        if ($row->archivo_path && Storage::disk('local')->exists($row->archivo_path)) {
            Storage::disk('local')->delete($row->archivo_path);
        }
        DB::table('erp_control_facturas_validaciones')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function alertas(Request $request): JsonResponse
    {
        $rows = DB::table('erp_control_facturas_alertas as a')
            ->leftJoin('erp_control_facturas_validaciones as v', 'v.id', '=', 'a.validacion_id')
            ->where('a.leida', false)
            ->orderByDesc('a.created_at')
            ->select([
                'a.id', 'a.validacion_id', 'a.tipo_alerta', 'a.severidad', 'a.mensaje', 'a.created_at',
                'v.archivo_nombre', 'v.resultado_global',
            ])
            ->limit(100)->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function marcarAlertaLeida(Request $request, int $id): JsonResponse
    {
        $this->service->marcarAlertaLeida($id);
        return response()->json(['ok' => true]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }
}
