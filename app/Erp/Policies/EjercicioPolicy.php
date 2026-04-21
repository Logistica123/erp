<?php

namespace App\Erp\Policies;

use App\Erp\Models\Ejercicio;
use App\Models\User;

class EjercicioPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->erpPerfil?->tienePermiso('contabilidad.asientos.ver') ?? false;
    }

    public function view(User $user, Ejercicio $ejercicio): bool
    {
        return $this->mismaEmpresa($user, $ejercicio->empresa_id) && $this->viewAny($user);
    }

    public function cerrar(User $user, Ejercicio $ejercicio): bool
    {
        return $this->mismaEmpresa($user, $ejercicio->empresa_id)
            && ($user->erpPerfil?->tienePermiso('contabilidad.ejercicios.cerrar') ?? false);
    }

    public function reabrir(User $user, Ejercicio $ejercicio): bool
    {
        return $this->mismaEmpresa($user, $ejercicio->empresa_id)
            && ($user->erpPerfil?->tienePermiso('contabilidad.ejercicios.reabrir') ?? false);
    }

    private function mismaEmpresa(User $user, ?int $empresaId): bool
    {
        return $user->erpPerfil?->empresa_id === $empresaId;
    }
}
