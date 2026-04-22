<?php

namespace App\Erp\Http\Middleware;

use App\Erp\Models\Sesion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación ERP (SPEC_01 §5, §10):
 *  1) Token Sanctum válido → $request->user()
 *  2) Perfil ERP existente con acceso_erp=1 y no bloqueado
 *  3) Si viene X-ERP-Session:
 *       - existe, no cerrada, no expirada
 *       - user_id coincide con el autenticado
 *       - si perfil.mfa_habilitado, mfa_verificado=1
 *     Deja la sesión en $request->attributes->get('erp_sesion') para middlewares
 *     subsiguientes (ej. ErpRequireMfaFresh).
 *  4) Si no viene el header, fallback al flujo legacy (token name *:mfa_ok*).
 *     Esto mantiene compatibilidad hacia clientes que aún no mandan el header.
 *  5) Actualiza ultimo_uso de la sesión en cada request.
 */
class ErpAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTH']], 401);
        }

        $perfil = $user->erpPerfil;

        if (! $perfil || ! $perfil->acceso_erp) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIN_ACCESO_ERP']], 403);
        }

        if ($perfil->estaBloqueado()) {
            return response()->json(['ok' => false, 'error' => ['code' => 'USUARIO_BLOQUEADO']], 423);
        }

        $sesionId = $request->header('X-ERP-Session');
        if ($sesionId) {
            $sesion = Sesion::find($sesionId);
            if (! $sesion || ! $sesion->estaActiva() || $sesion->user_id !== $user->id) {
                return response()->json(['ok' => false, 'error' => ['code' => 'SESION_INVALIDA']], 401);
            }

            if ($perfil->mfa_habilitado && ! $sesion->mfa_verificado) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'MFA_REQUERIDA'],
                    'mfa_required' => true,
                ], 401);
            }

            $sesion->update(['ultimo_uso' => now()]);
            $request->attributes->set('erp_sesion', $sesion);
        } elseif ($perfil->mfa_habilitado) {
            $tokenName = $user->currentAccessToken()?->name ?? '';
            if (! str_contains($tokenName, ':mfa_ok')) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'MFA_REQUERIDA'],
                    'mfa_required' => true,
                ], 401);
            }
        }

        return $next($request);
    }
}
