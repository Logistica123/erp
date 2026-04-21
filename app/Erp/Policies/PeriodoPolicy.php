<?php

namespace App\Erp\Policies;

use App\Erp\Models\Periodo;
use App\Models\User;

class PeriodoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->erpPerfil?->tienePermiso('contabilidad.asientos.ver') ?? false;
    }

    public function view(User $user, Periodo $periodo): bool
    {
        return $this->viewAny($user);
    }

    public function cerrar(User $user, Periodo $periodo): bool
    {
        return $user->erpPerfil?->tienePermiso('contabilidad.periodos.cerrar') ?? false;
    }

    public function reabrir(User $user, Periodo $periodo): bool
    {
        // Solo super_admin, vía permiso sensible.
        return $user->erpPerfil?->tienePermiso('contabilidad.periodos.reabrir') ?? false;
    }
}
