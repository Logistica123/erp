<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\Seguros\ParserSeguroFactory;
use App\Erp\Services\Seguros\ProcesamientoSeguroService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Procesamiento de Seguro (Compras): subir PDF → analizar → revisar → cargar al
 * Libro IVA Compras y emitir el TXT del Libro IVA Digital.
 */
class ProcesamientoSeguroController
{
    public function __construct(
        private readonly ProcesamientoSeguroService $service,
        private readonly ParserSeguroFactory $factory,
    ) {}

    /** Aseguradoras soportadas (para la UI). */
    public function soportadas(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->factory->soportadas()]);
    }

    /** Sube el PDF y devuelve el detalle extraído (preview, sin guardar). */
    public function analizar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        try {
            $data = $this->service->analizar($request->file('archivo')->getRealPath());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** Carga el comprobante revisado en el Libro IVA Compras + emite el TXT. */
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
            'imp_neto_gravado_21' => ['required', 'numeric'],
            'imp_iva_21' => ['required', 'numeric'],
            'imp_percepciones_iva' => ['nullable', 'numeric'],
            'imp_otros_tributos' => ['nullable', 'numeric'],
            'imp_total' => ['required', 'numeric'],
        ]);
        try {
            $factura = $this->service->cargar($data, $request->user());
            $txt = $this->service->emitirTxt([$factura->id]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }
        return response()->json(['ok' => true, 'data' => [
            'factura_id' => $factura->id,
            'txt_cbte' => $txt['cbte'],
            'txt_alicuotas' => $txt['alicuotas'],
        ]]);
    }

    /** Re-descarga el TXT de un comprobante ya cargado. */
    public function txt(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'compras.libro_iva.importar');
        FacturaCompra::findOrFail($id);
        try {
            $txt = $this->service->emitirTxt([$id]);
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
