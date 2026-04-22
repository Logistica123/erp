<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Permiso;
use App\Erp\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles, permisos y "mis permisos" (SPEC_01 §5.1).
 *
 *   GET /api/erp/roles            Catálogo de roles
 *   GET /api/erp/permisos         Catálogo de permisos (con flag sensible)
 *   GET /api/erp/mi-permisos      Permisos efectivos del usuario autenticado
 */
class RolesPermisosController
{
    public function rolesIndex(Request $request): JsonResponse
    {
        $roles = Rol::with('permisos:id,codigo,sensible')
            ->when($request->query('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($request->query('activo'), fn ($q, $v) => $q->where('activo', (bool) $v))
            ->orderBy('nivel_jerarquia')
            ->get();

        return response()->json(['ok' => true, 'data' => $roles]);
    }

    public function permisosIndex(Request $request): JsonResponse
    {
        $permisos = Permiso::query()
            ->when($request->query('modulo'), fn ($q, $v) => $q->where('modulo', $v))
            ->when($request->query('sensible'), fn ($q, $v) => $q->where('sensible', (bool) $v))
            ->orderBy('modulo')->orderBy('entidad')->orderBy('accion')
            ->get();

        return response()->json(['ok' => true, 'data' => $permisos]);
    }

    public function misPermisos(Request $request): JsonResponse
    {
        $perfil = $request->user()->erpPerfil()->with('roles.permisos:id,codigo,sensible')->first();

        if (! $perfil) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        $permisos = $perfil->roles
            ->pluck('permisos')
            ->flatten()
            ->unique('codigo')
            ->values()
            ->map(fn ($p) => ['codigo' => $p->codigo, 'sensible' => (bool) $p->sensible]);

        return response()->json(['ok' => true, 'data' => $permisos]);
    }
}
