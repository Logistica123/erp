<?php

namespace App\Erp\Http\Middleware;

use App\Erp\Models\Sesion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la sesión ERP tenga MFA verificado dentro de la ventana de
 * frescura (SPEC_01 §10: 15 min para permisos sensible=1).
 *
 * Aplicar después de `erp.auth`, sobre rutas de escritura crítica (cierres
 * de período/ejercicio, anulación de asiento, mutaciones de config, etc.).
 * Uso: Route::middleware(['auth:sanctum', 'erp.auth', 'erp.mfa.fresh'])->...
 */
class ErpRequireMfaFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $sesion = $request->attributes->get('erp_sesion');

        if (! $sesion instanceof Sesion) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'SESION_REQUERIDA'],
            ], 401);
        }

        if (! $sesion->mfaFresco()) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'MFA_REFRESH_REQUERIDO'],
                'mfa_refresh_required' => true,
                'freshness_minutes' => Sesion::MFA_FRESHNESS_MINUTES,
            ], 401);
        }

        return $next($request);
    }
}
