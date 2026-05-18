<?php

namespace App\Erp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v1.24 — ABM del mapeo concepto AFIP → cuenta contable usado por el
 * importador del Libro IVA Compras al generar asientos.
 *
 *   GET  /api/erp/contabilidad/iva-mapeo                 lista activa
 *   PUT  /api/erp/contabilidad/iva-mapeo/{concepto}      cambia la cuenta
 *
 * Permiso: contabilidad.iva_mapeo.editar (super_admin + contador).
 * GET es read-only y solo requiere usuario logueado.
 */
class ConfiguracionIvaMapeoController
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        $rows = DB::table('erp_configuracion_iva_mapeo as m')
            ->join('erp_cuentas_contables as c', 'c.id', '=', 'm.cuenta_contable_id')
            ->where('m.empresa_id', $empresaId)
            ->where('m.activo', 1)
            ->orderBy('m.concepto_csv')
            ->get([
                'm.id', 'm.concepto_csv', 'm.descripcion', 'm.cuenta_contable_id',
                'c.codigo as cuenta_codigo', 'c.nombre as cuenta_nombre',
                'c.imputable as cuenta_imputable',
                'm.observaciones', 'm.activo', 'm.updated_at',
            ]);

        $puedeEditar = $request->user()?->erpPerfil?->tienePermiso('contabilidad.iva_mapeo.editar') ?? false;

        return response()->json(['ok' => true, 'data' => $rows, 'puede_editar' => $puedeEditar]);
    }

    public function update(Request $request, string $concepto): JsonResponse
    {
        $this->mustHave($request, 'contabilidad.iva_mapeo.editar');

        $data = $request->validate([
            'cuenta_contable_id' => ['required', 'integer', 'exists:erp_cuentas_contables,id'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        $empresaId = (int) ($request->header('X-Empresa-Id') ?: 1);

        $cuenta = DB::table('erp_cuentas_contables')
            ->where('id', $data['cuenta_contable_id'])
            ->where('empresa_id', $empresaId)
            ->first(['id', 'codigo', 'nombre', 'imputable']);

        if (! $cuenta) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'CUENTA_NO_ENCONTRADA',
                'message' => 'La cuenta no existe en la empresa.',
            ]], 422);
        }
        if (! $cuenta->imputable) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'CUENTA_NO_IMPUTABLE',
                'message' => "La cuenta {$cuenta->codigo} {$cuenta->nombre} es padre no imputable. Elegí una hija imputable.",
            ]], 422);
        }

        $existe = DB::table('erp_configuracion_iva_mapeo')
            ->where('empresa_id', $empresaId)
            ->where('concepto_csv', $concepto)
            ->exists();

        if (! $existe) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'CONCEPTO_NO_ENCONTRADO',
                'message' => "El concepto '{$concepto}' no existe en el mapeo.",
            ]], 404);
        }

        DB::table('erp_configuracion_iva_mapeo')
            ->where('empresa_id', $empresaId)
            ->where('concepto_csv', $concepto)
            ->update([
                'cuenta_contable_id' => $cuenta->id,
                'observaciones' => $data['observaciones'] ?? null,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'data' => [
            'concepto_csv' => $concepto,
            'cuenta_codigo' => $cuenta->codigo,
            'cuenta_nombre' => $cuenta->nombre,
        ]]);
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
}
