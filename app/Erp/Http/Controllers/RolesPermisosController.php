<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Permiso;
use App\Erp\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Roles, permisos y "mis permisos" (SPEC_01 §5.1 + v1.55 Bloque C).
 *
 *   GET    /api/erp/roles                 Catálogo de roles
 *   POST   /api/erp/roles                 Crea rol
 *   PATCH  /api/erp/roles/{id}            Edita rol (protegido: solo descripción/activo)
 *   DELETE /api/erp/roles/{id}            Borra rol (no protegido, sin usuarios)
 *   PUT    /api/erp/roles/{id}/permisos   Sync permisos del rol
 *   GET    /api/erp/permisos              Catálogo de permisos (con flag sensible)
 *   GET    /api/erp/mi-permisos           Permisos efectivos del usuario autenticado
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

    /** v1.55 Bloque C — crear rol. */
    public function rolesStore(Request $request): JsonResponse
    {
        $empresaId = $request->user()->erpPerfil?->empresa_id ?? 1;
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('erp_roles', 'codigo')->where('empresa_id', $empresaId)],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:400'],
            'nivel_jerarquia' => ['nullable', 'integer', 'min:1', 'max:99'],
            'permisos' => ['sometimes', 'array'],
            'permisos.*' => ['integer', 'exists:erp_permisos,id'],
        ]);

        $rol = DB::transaction(function () use ($data, $empresaId) {
            $rol = Rol::create([
                'empresa_id' => $empresaId,
                'codigo' => $data['codigo'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'nivel_jerarquia' => $data['nivel_jerarquia'] ?? 50,
                'protegido' => false,
                'activo' => true,
            ]);
            if (! empty($data['permisos'])) {
                $rol->permisos()->sync($data['permisos']);
            }

            return $rol->fresh('permisos:id,codigo,sensible');
        });

        return response()->json(['ok' => true, 'data' => $rol], 201);
    }

    /** v1.55 Bloque C — editar rol. Los protegidos solo permiten descripción/activo. */
    public function rolesUpdate(Request $request, int $id): JsonResponse
    {
        $rol = Rol::findOrFail($id);

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:100'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:400'],
            'nivel_jerarquia' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        if ($rol->protegido) {
            $prohibidos = array_intersect(array_keys($data), ['nombre', 'nivel_jerarquia']);
            if ($prohibidos) {
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'ROL_PROTEGIDO',
                    'message' => 'Rol protegido del sistema: solo se puede editar descripción y activo.',
                ]], 422);
            }
        }

        $rol->update($data);

        return response()->json(['ok' => true, 'data' => $rol->fresh('permisos:id,codigo,sensible')]);
    }

    /** v1.55 Bloque C — borrar rol (no protegido y sin usuarios asignados). */
    public function rolesDestroy(int $id): JsonResponse
    {
        $rol = Rol::findOrFail($id);

        if ($rol->protegido) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'ROL_PROTEGIDO', 'message' => 'No se puede borrar un rol protegido del sistema.',
            ]], 422);
        }

        $usuarios = DB::table('erp_usuario_rol')->where('rol_id', $id)->count();
        if ($usuarios > 0) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'ROL_CON_USUARIOS',
                'message' => "El rol tiene {$usuarios} usuario(s) asignado(s). Reasignalos antes de borrar.",
            ]], 409);
        }

        DB::transaction(function () use ($rol) {
            $rol->permisos()->sync([]);
            $rol->delete();
        });

        return response()->json(['ok' => true]);
    }

    /** v1.55 Bloque C — reemplaza el set completo de permisos del rol. */
    public function rolesSyncPermisos(Request $request, int $id): JsonResponse
    {
        $rol = Rol::findOrFail($id);

        $data = $request->validate([
            'permisos' => ['present', 'array'],
            'permisos.*' => ['integer', 'exists:erp_permisos,id'],
        ]);

        $rol->permisos()->sync($data['permisos']);

        return response()->json(['ok' => true, 'data' => $rol->fresh('permisos:id,codigo,sensible')]);
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
            return response()->json(['ok' => true, 'data' => [], 'roles' => [], 'es_super_admin' => false]);
        }

        $permisos = $perfil->roles
            ->pluck('permisos')
            ->flatten()
            ->unique('codigo')
            ->values()
            ->map(fn ($p) => ['codigo' => $p->codigo, 'sensible' => (bool) $p->sensible]);

        // Item 8 Fase 2A — extensión ADITIVA (B.4): `data` mantiene su shape
        // exacto (5 páginas lo consumen); roles y es_super_admin son claves
        // top-level nuevas para usePermisos()/sidebar en 2C.
        return response()->json([
            'ok' => true,
            'data' => $permisos,
            'roles' => $perfil->roles->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => $r->nombre])->values(),
            'es_super_admin' => $perfil->roles->contains('codigo', 'super_admin'),
        ]);
    }
}
