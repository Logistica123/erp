<?php

namespace App\Erp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Item 8 (auditoría 2026-07-12) — gate de permiso fino por ruta.
 *
 * Uso: ->middleware('erp.permiso:inversiones.crear')
 *
 * Capa 3 del modelo apilado (después de erp.auth y erp.superadmin, antes
 * de erp.mfa.fresh). Reusa UsuarioPerfil::tienePermiso() — permisos por rol
 * + permisos temporales v1.29, sin lógica nueva de resolución.
 *
 * - Bypass super_admin (config erp.superadmin_bypass, default true): un
 *   hueco de matriz nunca puede bloquear a los administradores.
 * - Modo 'log' (config erp.permisos_modo): evalúa y loguea la denegación
 *   simulada SIN bloquear — rollout en observación (Fase 2A en prod).
 */
class ErpPermiso
{
    public function handle(Request $request, Closure $next, string $codigo): Response
    {
        $perfil = $request->user()?->erpPerfil;

        if (! $perfil) {
            return $this->denegar($request, $next, $codigo, 'SIN_PERFIL');
        }

        if (config('erp.superadmin_bypass', true)
            && $perfil->roles()->where('codigo', 'super_admin')->exists()) {
            return $next($request);
        }

        if ($perfil->tienePermiso($codigo)) {
            return $next($request);
        }

        return $this->denegar($request, $next, $codigo, 'PERMISO_REQUERIDO');
    }

    private function denegar(Request $request, Closure $next, string $codigo, string $motivo): Response
    {
        if (config('erp.permisos_modo', 'enforce') === 'log') {
            Log::warning('permiso.denegado-simulado', [
                'permiso' => $codigo,
                'motivo' => $motivo,
                'user_id' => $request->user()?->id,
                'ruta' => $request->method().' '.$request->path(),
            ]);

            return $next($request);
        }

        return response()->json(['ok' => false, 'error' => [
            'code' => 'PERMISO_REQUERIDO',
            'permiso' => $codigo,
            'message' => "No tenés el permiso '{$codigo}' para esta acción. Pedile acceso a un administrador.",
        ]], 403);
    }
}
