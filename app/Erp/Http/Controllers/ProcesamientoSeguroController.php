<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Services\Seguros\ParserSeguroFactory;
use App\Erp\Services\Seguros\ProcesamientoSeguroService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Procesamiento de Seguro — módulo AUTÓNOMO. Sube PDFs de pólizas, los guarda
 * en su propia tabla (sin impactar el resto del ERP), detecta duplicados y
 * emite el TXT del Libro IVA Digital para importar a AFIP.
 */
class ProcesamientoSeguroController
{
    public function __construct(
        private readonly ProcesamientoSeguroService $service,
        private readonly ParserSeguroFactory $factory,
    ) {}

    public function soportadas(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->factory->soportadas()]);
    }

    /** Lista los comprobantes de seguro ya cargados en el módulo. */
    public function index(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        return response()->json(['ok' => true, 'data' => $this->service->listar()]);
    }

    /** Sube uno o varios PDFs y devuelve el detalle extraído de cada uno (sin guardar). */
    public function analizar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $request->validate([
            'archivos' => ['required', 'array', 'min:1'],
            'archivos.*' => ['file', 'mimes:pdf', 'max:20480'],
        ]);
        $out = [];
        foreach ($request->file('archivos') as $file) {
            $nombre = $file->getClientOriginalName();
            try {
                $data = $this->service->analizar($file->getRealPath());
                $data['nombre_archivo'] = $nombre;
                $data['analisis_ok'] = true;
                $out[] = $data;
            } catch (DomainException $e) {
                [$code] = explode(':', $e->getMessage(), 2);
                $out[] = ['analisis_ok' => false, 'nombre_archivo' => $nombre, 'error' => $code, 'mensaje' => $e->getMessage()];
            }
        }
        return response()->json(['ok' => true, 'data' => $out]);
    }

    /** Guarda uno o varios comprobantes revisados en el módulo (autónomo). */
    public function cargar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.aseguradora' => ['nullable', 'string'],
            'items.*.cuit_aseguradora' => ['required', 'string'],
            'items.*.fecha_emision' => ['required', 'date'],
            'items.*.fecha_imputacion' => ['nullable', 'date'],
            'items.*.periodo_anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'items.*.periodo_mes' => ['required', 'integer', 'min:1', 'max:12'],
            'items.*.punto_venta' => ['required', 'integer', 'min:0'],
            'items.*.numero' => ['required', 'integer', 'min:0'],
            'items.*.tipo_comprobante_id' => ['required', 'integer', 'in:90,99'],
            'items.*.poliza' => ['nullable', 'string'],
            'items.*.comprobante_ref' => ['nullable', 'string'],
            'items.*.contenido_hash' => ['required', 'string', 'size:64'],
            'items.*.nombre_archivo' => ['nullable', 'string'],
            'items.*.imp_neto_gravado_21' => ['required', 'numeric'],
            'items.*.imp_iva_21' => ['required', 'numeric'],
            'items.*.imp_percepciones_iva' => ['nullable', 'numeric'],
            'items.*.imp_otros_tributos' => ['nullable', 'numeric'],
            'items.*.imp_total' => ['required', 'numeric'],
            'items.*.crudos' => ['nullable', 'array'],
        ]);
        $res = $this->service->cargarLote($data['items'], $request->user());
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function eliminar(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        try {
            $this->service->eliminar($id, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true]);
    }

    /** Emite el TXT (CBTE + ALICUOTAS) de los comprobantes indicados (o todos). */
    public function txt(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $data = $request->validate([
            'ids' => ['nullable', 'array'], 'ids.*' => ['integer'],
            'periodo_anio' => ['nullable', 'integer'], 'periodo_mes' => ['nullable', 'integer'],
        ]);
        try {
            $txt = $this->service->emitirTxt($data['ids'] ?? [], 1, $data['periodo_anio'] ?? null, $data['periodo_mes'] ?? null);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $txt]);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        [$code] = explode(':', $e->getMessage(), 2);
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 422);
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
