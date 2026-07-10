<?php

namespace App\Erp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1.55 Bloque C — gate del módulo Administración.
 *
 * Las rutas admin (usuarios, roles, diarios, empresa, config) no tenían
 * ningún chequeo de permiso: cualquier usuario con acceso_erp podía crear
 * usuarios o tocar config. Este middleware exige rol super_admin, mismo
 * criterio que PermisosTemporalesController::mustHave (CP-55-C3).
 */
class ErpSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->roles()->where('codigo', 'super_admin')->exists()) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => 'Solo super_admin puede acceder al módulo Administración.',
            ]], 403);
        }

        return $next($request);
    }
}
