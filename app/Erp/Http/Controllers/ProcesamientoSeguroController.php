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

    /** Sube el PDF y devuelve el detalle extraído + flag de duplicado (sin guardar). */
    public function analizar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        try {
            $data = $this->service->analizar($request->file('archivo')->getRealPath());
            $data['nombre_archivo'] = $request->file('archivo')->getClientOriginalName();
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** Guarda el comprobante revisado en el módulo (autónomo). */
    public function cargar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $data = $request->validate([
            'aseguradora' => ['nullable', 'string'],
            'cuit_aseguradora' => ['required', 'string'],
            'fecha_emision' => ['required', 'date'],
            'fecha_imputacion' => ['nullable', 'date'],
            'punto_venta' => ['required', 'integer', 'min:0'],
            'numero' => ['required', 'integer', 'min:0'],
            'tipo_comprobante_id' => ['required', 'integer', 'in:90,99'],
            'poliza' => ['nullable', 'string'],
            'comprobante_ref' => ['nullable', 'string'],
            'contenido_hash' => ['required', 'string', 'size:64'],
            'nombre_archivo' => ['nullable', 'string'],
            'imp_neto_gravado_21' => ['required', 'numeric'],
            'imp_iva_21' => ['required', 'numeric'],
            'imp_percepciones_iva' => ['nullable', 'numeric'],
            'imp_otros_tributos' => ['nullable', 'numeric'],
            'imp_total' => ['required', 'numeric'],
            'crudos' => ['nullable', 'array'],
        ]);
        try {
            $row = $this->service->cargar($data, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $row]);
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
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        try {
            $txt = $this->service->emitirTxt($data['ids'] ?? []);
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
