<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\AsientoPlantilla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plantillas/modelos de asiento contable (asientos repetitivos: sueldos,
 * alquiler, etc). Usa la tabla base erp_asientos_plantilla con json_definicion.
 *
 *   GET    /asiento-plantillas        listado
 *   GET    /asiento-plantillas/{id}   detalle (con líneas enriquecidas)
 *   POST   /asiento-plantillas        crear
 *   DELETE /asiento-plantillas/{id}   eliminar
 *
 * json_definicion = { glosa_default, observaciones_default, lineas: [
 *   { cuenta_id, centro_costo_id, auxiliar_id, glosa, debe, haber } ] }
 */
class AsientoPlantillasController
{
    public function index(Request $request): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;
        $rows = AsientoPlantilla::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'descripcion', 'diario_id']);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;
        $pl = AsientoPlantilla::where('empresa_id', $empresaId)->findOrFail($id);

        $def = $pl->json_definicion ?? [];
        $lineas = $def['lineas'] ?? [];

        // Enriquecer cada línea con código/nombre de cuenta para el form.
        $cuentaIds = array_filter(array_map(fn ($l) => $l['cuenta_id'] ?? null, $lineas));
        $cuentas = $cuentaIds
            ? DB::table('erp_cuentas_contables')->whereIn('id', $cuentaIds)->pluck('codigo', 'id')->all()
            : [];

        $lineasOut = array_map(fn ($l) => [
            'cuenta_id' => $l['cuenta_id'] ?? null,
            'cuenta_codigo' => $l['cuenta_id'] ? ($cuentas[$l['cuenta_id']] ?? '') : '',
            'centro_costo_id' => $l['centro_costo_id'] ?? null,
            'auxiliar_id' => $l['auxiliar_id'] ?? null,
            'glosa' => $l['glosa'] ?? '',
            'debe' => (float) ($l['debe'] ?? 0),
            'haber' => (float) ($l['haber'] ?? 0),
        ], $lineas);

        return response()->json(['ok' => true, 'data' => [
            'id' => $pl->id,
            'nombre' => $pl->nombre,
            'descripcion' => $pl->descripcion,
            'diario_id' => $pl->diario_id,
            'glosa_default' => $def['glosa_default'] ?? null,
            'observaciones_default' => $def['observaciones_default'] ?? null,
            'lineas' => $lineasOut,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:400'],
            'diario_id' => ['required', 'integer', 'exists:erp_diarios,id'],
            'glosa_default' => ['nullable', 'string', 'max:300'],
            'observaciones_default' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.cuenta_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'lineas.*.centro_costo_id' => ['nullable', 'integer'],
            'lineas.*.auxiliar_id' => ['nullable', 'integer'],
            'lineas.*.glosa' => ['nullable', 'string', 'max:300'],
            'lineas.*.debe' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.haber' => ['nullable', 'numeric', 'min:0'],
        ]);
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;

        // Solo líneas con cuenta. Si no queda ninguna, error.
        $lineas = array_values(array_filter($data['lineas'], fn ($l) => ! empty($l['cuenta_id'])));
        if (empty($lineas)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'SIN_CUENTAS', 'message' => 'La plantilla necesita al menos una línea con cuenta.',
            ]], 422);
        }

        $codigo = $this->generarCodigo($empresaId, $data['nombre']);

        $pl = AsientoPlantilla::create([
            'empresa_id' => $empresaId,
            'codigo' => $codigo,
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'diario_id' => $data['diario_id'],
            'json_definicion' => [
                'glosa_default' => $data['glosa_default'] ?? null,
                'observaciones_default' => $data['observaciones_default'] ?? null,
                'lineas' => array_map(fn ($l) => [
                    'cuenta_id' => (int) $l['cuenta_id'],
                    'centro_costo_id' => $l['centro_costo_id'] ?? null,
                    'auxiliar_id' => $l['auxiliar_id'] ?? null,
                    'glosa' => $l['glosa'] ?? '',
                    'debe' => round((float) ($l['debe'] ?? 0), 2),
                    'haber' => round((float) ($l['haber'] ?? 0), 2),
                ], $lineas),
            ],
            'activo' => true,
        ]);

        return response()->json(['ok' => true, 'data' => ['id' => $pl->id, 'nombre' => $pl->nombre]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;
        $pl = AsientoPlantilla::where('empresa_id', $empresaId)->findOrFail($id);
        $pl->delete();
        return response()->json(['ok' => true]);
    }

    private function generarCodigo(int $empresaId, string $nombre): string
    {
        $base = strtoupper(Str::slug($nombre, '_'));
        $base = substr('PL_' . $base, 0, 36);
        $codigo = $base;
        $i = 1;
        while (AsientoPlantilla::where('empresa_id', $empresaId)->where('codigo', $codigo)->exists()) {
            $codigo = substr($base, 0, 36) . '_' . (++$i);
        }
        return $codigo;
    }
}
