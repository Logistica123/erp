<?php

namespace App\Erp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida que el request tenga un token Sanctum válido, que el usuario tenga
 * perfil ERP activo y que haya completado MFA si su rol lo requiere.
 *
 * Para proteger operaciones sensibles, sumar el middleware `erp.mfa` o
 * verificar el permiso con policies (ver handoff §8.1-8.2).
 */
class ErpAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $perfil = $user->erpPerfil;

        if (! $perfil || ! $perfil->acceso_erp) {
            return response()->json(['message' => 'Sin acceso al ERP.'], 403);
        }

        if ($perfil->estaBloqueado()) {
            return response()->json(['message' => 'Usuario bloqueado temporalmente.'], 423);
        }

        if ($perfil->mfa_habilitado) {
            $tokenName = $user->currentAccessToken()?->name ?? '';
            if (! str_contains($tokenName, ':mfa_ok')) {
                return response()->json([
                    'message' => 'Requiere verificación MFA.',
                    'mfa_required' => true,
                ], 401);
            }
        }

        return $next($request);
    }
}
