<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * v1.55 Bloque C — datos de la empresa (erp_empresas).
 *
 * El sistema es mono-empresa (la del perfil, default 1). El catálogo viejo
 * GET /empresas/actual (CatalogosController) devuelve un subset y lo usan
 * otras pantallas — no se toca. Este controller es la ficha completa del
 * módulo Admin:
 *   GET   /api/erp/admin/empresa    Ficha completa
 *   PATCH /api/erp/admin/empresa    Edita datos fiscales
 */
class EmpresasController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->empresa($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        $data = $request->validate([
            'razon_social' => ['sometimes', 'string', 'max:200'],
            'nombre_fantasia' => ['sometimes', 'nullable', 'string', 'max:200'],
            'cuit' => ['sometimes', 'digits:11'],
            'condicion_iva' => ['sometimes', Rule::in(['RI', 'MONOTRIBUTO', 'EXENTO', 'CF'])],
            'domicilio_fiscal' => ['sometimes', 'nullable', 'string', 'max:300'],
            'iibb_nro' => ['sometimes', 'nullable', 'string', 'max:20'],
            'iibb_regimen' => ['sometimes', 'nullable', Rule::in(['CM', 'LOCAL'])],
            'iibb_jurisdiccion_sede' => ['sometimes', 'nullable', 'string', 'max:3'],
            'fecha_inicio_actividades' => ['sometimes', 'nullable', 'date'],
            'aplica_rt6' => ['sometimes', 'boolean'],
        ]);

        $empresa->update($data);

        return response()->json(['ok' => true, 'data' => $empresa->fresh()]);
    }

    private function empresa(Request $request): Empresa
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        return Empresa::findOrFail($empresaId);
    }
}
