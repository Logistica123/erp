<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Empresa;
use App\Erp\Models\Sesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Sesiones ERP independientes del token Sanctum (SPEC_01 §5.1).
 *
 *   POST   /api/erp/sesiones       Inicia sesión ERP — devuelve UUID para X-ERP-Session
 *   POST   /api/erp/sesiones/mfa   Marca la sesión como MFA-verificada (setea mfa_verificado_at)
 *   DELETE /api/erp/sesiones       Cierra la sesión (setea cerrada_en)
 *
 * El header X-ERP-Session acompaña a todo request posterior. El middleware
 * ErpAuth valida existencia/expiración y ErpRequireMfaFresh valida frescura
 * MFA (15 min) para endpoints sensibles.
 */
class SesionesController
{
    private const TTL_HORAS = 8;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTH']], 401);
        }

        $perfil = $user->erpPerfil;
        if (! $perfil || ! $perfil->acceso_erp) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIN_ACCESO_ERP']], 403);
        }

        $empresa = Empresa::orderBy('id')->first();
        if (! $empresa) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIN_EMPRESA']], 500);
        }

        $sesion = Sesion::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'empresa_id' => $empresa->id,
            'mfa_verificado' => false,
            'mfa_verificado_at' => null,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 400),
            'inicio' => now(),
            'ultimo_uso' => now(),
            'expira_en' => now()->addHours(self::TTL_HORAS),
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $sesion->id,
                'empresa_id' => $sesion->empresa_id,
                'expira_en' => $sesion->expira_en,
                'mfa_requerida' => (bool) $perfil->mfa_habilitado,
            ],
        ], 201);
    }

    public function mfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $sesionId = $request->header('X-ERP-Session');
        if (! $sesionId) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SESION_REQUERIDA']], 400);
        }

        $sesion = Sesion::find($sesionId);
        if (! $sesion || ! $sesion->estaActiva() || $sesion->user_id !== $request->user()->id) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SESION_INVALIDA']], 401);
        }

        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->mfa_habilitado || ! $perfil->mfa_secret) {
            return response()->json(['ok' => false, 'error' => ['code' => 'MFA_NO_CONFIGURADO']], 400);
        }

        $google2fa = new Google2FA();
        if (! $google2fa->verifyKey($perfil->mfa_secret, $data['code'])) {
            $perfil->increment('intentos_fallidos');
            if ($perfil->intentos_fallidos >= 5) {
                $perfil->update(['bloqueado_hasta' => now()->addMinutes(15)]);
            }

            return response()->json(['ok' => false, 'error' => ['code' => 'MFA_CODIGO_INVALIDO']], 401);
        }

        DB::transaction(function () use ($sesion, $perfil) {
            $sesion->update([
                'mfa_verificado' => true,
                'mfa_verificado_at' => now(),
                'ultimo_uso' => now(),
            ]);
            $perfil->update(['intentos_fallidos' => 0, 'ultimo_login' => now()]);
        });

        return response()->json([
            'ok' => true,
            'data' => [
                'mfa_verificado_at' => $sesion->fresh()->mfa_verificado_at,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $sesionId = $request->header('X-ERP-Session');
        if ($sesionId) {
            Sesion::where('id', $sesionId)
                ->whereNull('cerrada_en')
                ->update(['cerrada_en' => now(), 'motivo_cierre' => 'LOGOUT']);
        }

        return response()->json(['ok' => true]);
    }
}
