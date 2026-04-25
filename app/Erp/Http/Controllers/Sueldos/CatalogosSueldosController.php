<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\Categoria;
use App\Erp\Models\Sueldos\Concepto;
use App\Erp\Models\Sueldos\Convenio;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogos del módulo Sueldos: convenios, categorías, conceptos.
 * Lectura pública para cualquiera con sueldos.empleados.ver. La edición
 * de categorías requiere sueldos.empleados.editar; los conceptos los
 * edita solo super_admin (catálogo estable, mapean a cuentas contables).
 */
class CatalogosSueldosController extends Controller
{
    public function convenios(Request $request): JsonResponse
    {
        $this->requirePermiso($request, 'sueldos.empleados.ver');
        $rows = Convenio::query()
            ->when($request->boolean('solo_activos', true), fn ($q) => $q->where('activo', 1))
            ->orderBy('codigo')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function categorias(Request $request): JsonResponse
    {
        $this->requirePermiso($request, 'sueldos.empleados.ver');
        $rows = Categoria::with('convenio')
            ->when($request->query('convenio_id'), fn ($q, $v) => $q->where('convenio_id', (int) $v))
            ->when($request->boolean('solo_activas', true), fn ($q) => $q->where('activa', 1))
            ->orderBy('convenio_id')->orderBy('nivel_jerarquia')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function categoriaStore(Request $request): JsonResponse
    {
        $this->requirePermiso($request, 'sueldos.empleados.editar');
        $datos = $request->validate([
            'convenio_id'      => ['required', 'integer', 'exists:erp_emp_convenios,id'],
            'codigo'           => ['required', 'string', 'max:30'],
            'nombre'           => ['required', 'string', 'max:100'],
            'nivel_jerarquia'  => ['nullable', 'integer', 'min:0', 'max:99'],
            'descripcion'      => ['nullable', 'string'],
            'activa'           => ['nullable', 'boolean'],
        ]);
        $cat = Categoria::create($datos);
        return response()->json(['ok' => true, 'data' => $cat], 201);
    }

    public function categoriaUpdate(Request $request, int $id): JsonResponse
    {
        $this->requirePermiso($request, 'sueldos.empleados.editar');
        $cat = Categoria::findOrFail($id);
        $datos = $request->validate([
            'codigo'           => ['nullable', 'string', 'max:30'],
            'nombre'           => ['nullable', 'string', 'max:100'],
            'nivel_jerarquia'  => ['nullable', 'integer', 'min:0', 'max:99'],
            'descripcion'      => ['nullable', 'string'],
            'activa'           => ['nullable', 'boolean'],
        ]);
        $cat->update($datos);
        return response()->json(['ok' => true, 'data' => $cat->fresh('convenio')]);
    }

    public function conceptos(Request $request): JsonResponse
    {
        $this->requirePermiso($request, 'sueldos.empleados.ver');
        $rows = Concepto::with(['cuentaDebe', 'cuentaHaber'])
            ->when($request->query('tipo'),  fn ($q, $v) => $q->where('tipo', $v))
            ->when($request->query('signo'), fn ($q, $v) => $q->where('signo', $v))
            ->when($request->boolean('solo_activos', true), fn ($q) => $q->where('activo', 1))
            ->orderBy('orden')
            ->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function conceptoUpdate(Request $request, int $id): JsonResponse
    {
        // Solo super_admin (verificación inline).
        $perfil = $request->user()->erpPerfil;
        $esAdmin = $perfil && $perfil->roles()->where('codigo', 'super_admin')->exists();
        if (! $esAdmin) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO']], 403);
        }
        $concepto = Concepto::findOrFail($id);
        $datos = $request->validate([
            'nombre'          => ['nullable', 'string', 'max:100'],
            'tipo'            => ['nullable', 'in:REMUNERATIVO,NO_REMUNERATIVO,DESCUENTO_LEGAL,DESCUENTO_OTRO,SAC,COMISION,AJUSTE'],
            'signo'           => ['nullable', 'in:HABER,DESCUENTO'],
            'afecta_formal'   => ['nullable', 'boolean'],
            'afecta_efectivo' => ['nullable', 'boolean'],
            'afecta_mt'       => ['nullable', 'boolean'],
            'formula'         => ['nullable', 'string', 'max:200'],
            'cuenta_debe_id'  => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'cuenta_haber_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'orden'           => ['nullable', 'integer'],
            'activo'          => ['nullable', 'boolean'],
        ]);
        $concepto->update($datos);
        return response()->json(['ok' => true, 'data' => $concepto->fresh(['cuentaDebe', 'cuentaHaber'])]);
    }

    private function requirePermiso(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}
