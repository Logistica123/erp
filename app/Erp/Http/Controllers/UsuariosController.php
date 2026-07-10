<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Rol;
use App\Erp\Models\UsuarioPerfil;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Administración de usuarios ERP (SPEC_01 §5.1 + v1.55 Bloque C).
 *   GET    /api/erp/usuarios
 *   POST   /api/erp/usuarios                  Crea user + erp_usuario_perfil
 *   PATCH  /api/erp/usuarios/{id}              Edita datos / acceso / desbloquea
 *   PATCH  /api/erp/usuarios/{id}/password     Setea password nueva
 *   PATCH  /api/erp/usuarios/{id}/roles        Asigna/desasigna roles al perfil
 *
 * {id} es siempre erp_usuario_perfil.id (no user.id), igual que updateRoles.
 */
class UsuariosController
{
    public function index(Request $request): JsonResponse
    {
        $perfiles = UsuarioPerfil::with(['user:id,name,email', 'roles:id,codigo,nombre'])
            ->when($request->query('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($request->query('acceso_erp'), fn ($q, $v) => $q->where('acceso_erp', (bool) $v))
            ->orderBy('id')
            ->paginate(50);

        return response()->json($perfiles);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:14'],
            'empresa_id' => ['required', 'integer', 'exists:erp_empresas,id'],
            'legajo' => ['nullable', 'string', 'max:30'],
            'acceso_erp' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', 'exists:erp_roles,id'],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $perfil = UsuarioPerfil::create([
                'user_id' => $user->id,
                'empresa_id' => $data['empresa_id'],
                'legajo' => $data['legajo'] ?? null,
                'acceso_erp' => $data['acceso_erp'] ?? true,
                'mfa_habilitado' => false,
                'intentos_fallidos' => 0,
            ]);

            if (! empty($data['roles'])) {
                $pivot = [];
                foreach ($data['roles'] as $rolId) {
                    $pivot[$rolId] = [
                        'asignado_por' => $request->user()->id,
                        'asignado_en' => now(),
                    ];
                }
                $perfil->roles()->sync($pivot);
            }

            return $user->fresh(['erpPerfil.roles']);
        });

        return response()->json(['ok' => true, 'data' => $user], 201);
    }

    /** v1.55 Bloque C — editar datos, toggle acceso_erp, desbloquear. */
    public function update(Request $request, int $usuarioPerfilId): JsonResponse
    {
        $perfil = UsuarioPerfil::with('user')->findOrFail($usuarioPerfilId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($perfil->user_id)],
            'legajo' => ['sometimes', 'nullable', 'string', 'max:30'],
            'acceso_erp' => ['sometimes', 'boolean'],
            'desbloquear' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($perfil, $data) {
            $userData = array_intersect_key($data, array_flip(['name', 'email']));
            if ($userData) {
                $perfil->user->update($userData);
            }

            $perfilData = array_intersect_key($data, array_flip(['legajo', 'acceso_erp']));
            if (! empty($data['desbloquear'])) {
                $perfilData['bloqueado_hasta'] = null;
                $perfilData['intentos_fallidos'] = 0;
            }
            if ($perfilData) {
                $perfil->update($perfilData);
            }
        });

        return response()->json([
            'ok' => true,
            'data' => $perfil->fresh(['user:id,name,email', 'roles:id,codigo,nombre']),
        ]);
    }

    /** v1.55 Bloque C — reset de password (requiere MFA fresco + super_admin). */
    public function setPassword(Request $request, int $usuarioPerfilId): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:14'],
        ]);

        $perfil = UsuarioPerfil::with('user')->findOrFail($usuarioPerfilId);
        $perfil->user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['ok' => true, 'data' => ['user_id' => $perfil->user_id]]);
    }

    public function updateRoles(Request $request, int $usuarioPerfilId): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.id' => ['required', 'integer', 'exists:erp_roles,id'],
            'roles.*.vigente_hasta' => ['nullable', 'date'],
        ]);

        $perfil = UsuarioPerfil::findOrFail($usuarioPerfilId);

        $pivot = [];
        foreach ($data['roles'] as $r) {
            $pivot[$r['id']] = [
                'asignado_por' => $request->user()->id,
                'asignado_en' => now(),
                'vigente_hasta' => $r['vigente_hasta'] ?? null,
            ];
        }
        $perfil->roles()->sync($pivot);

        return response()->json([
            'ok' => true,
            'data' => $perfil->fresh('roles'),
        ]);
    }
}
