<?php

namespace App\Providers;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Diario;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Erp\Models\MovimientoAsiento;
use App\Erp\Models\Periodo;
use App\Erp\Models\Rol;
use App\Erp\Models\UsuarioPerfil;
use App\Erp\Observers\AuditableObserver;
use App\Erp\Observers\AuxiliarClienteObserver;
use App\Erp\Policies\AsientoPolicy;
use App\Erp\Policies\CuentaContablePolicy;
use App\Erp\Policies\EjercicioPolicy;
use App\Erp\Policies\PeriodoPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Observer de auditoría (RN-7) sobre entidades críticas.
        // Se excluye AuditLog y Sesion (alta frecuencia / ruido).
        $modelosAuditables = [
            Empresa::class,
            Ejercicio::class,
            Periodo::class,
            CuentaContable::class,
            Diario::class,
            CentroCosto::class,
            Auxiliar::class,
            Asiento::class,
            MovimientoAsiento::class,
            Rol::class,
            UsuarioPerfil::class,
        ];

        foreach ($modelosAuditables as $modelo) {
            $modelo::observe(AuditableObserver::class);
        }

        // ADDENDUM v1.14 — auto-crear CC al dar de alta un auxiliar tipo=Cliente.
        Auxiliar::observe(AuxiliarClienteObserver::class);

        // Policies del ERP
        Gate::policy(Asiento::class, AsientoPolicy::class);
        Gate::policy(CuentaContable::class, CuentaContablePolicy::class);
        Gate::policy(Ejercicio::class, EjercicioPolicy::class);
        Gate::policy(Periodo::class, PeriodoPolicy::class);
    }
}
