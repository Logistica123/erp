<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Erp\Models\Periodo;
use App\Erp\Models\Permiso;
use App\Erp\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $empresa = Empresa::with(['ejercicios.periodos', 'diarios', 'roles'])->first();

        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'db' => [
                'driver' => DB::connection()->getDriverName(),
                'name' => DB::connection()->getDatabaseName(),
            ],
            'empresa' => $empresa ? [
                'razon_social' => $empresa->razon_social,
                'cuit' => $empresa->cuit,
                'ejercicios' => $empresa->ejercicios->count(),
                'periodos_ejercicio_actual' => $empresa->ejercicios->first()?->periodos->count() ?? 0,
                'diarios' => $empresa->diarios->count(),
                'roles' => $empresa->roles->count(),
            ] : null,
            'counts' => [
                'monedas' => Moneda::count(),
                'ejercicios' => Ejercicio::count(),
                'periodos' => Periodo::count(),
                'diarios' => Diario::count(),
                'cuentas_contables' => CuentaContable::count(),
                'cuentas_imputables' => CuentaContable::where('imputable', true)->count(),
                'permisos' => Permiso::count(),
                'roles' => Rol::count(),
                'asientos' => Asiento::count(),
            ],
            'version' => '0.2.0',
        ]);
    }
}
