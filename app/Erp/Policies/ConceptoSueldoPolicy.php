<?php

namespace App\Erp\Policies;

use App\Erp\Models\Sueldos\Concepto;
use App\Models\User;

/**
 * Conceptos del recibo (catálogo). El padrón es público para roles con
 * sueldos.empleados.ver (necesario para mostrar la grilla del recibo);
 * la edición se restringe al super_admin (catálogo estable).
 */
class ConceptoSueldoPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tiene($user, 'sueldos.empleados.ver');
    }

    public function view(User $user, Concepto $c): bool
    {
        return $this->tiene($user, 'sueldos.empleados.ver');
    }

    public function update(User $user, Concepto $c): bool
    {
        return $user->erpPerfil?->roles()->where('codigo', 'super_admin')->exists() ?? false;
    }

    private function tiene(User $user, string $codigo): bool
    {
        return $user->erpPerfil?->tienePermiso($codigo) ?? false;
    }
}
