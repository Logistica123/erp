<?php

namespace App\Erp\Policies;

use App\Erp\Models\Sueldos\Empleado;
use App\Models\User;

/**
 * Permisos sobre el padrón de empleados (SPEC 08 §6).
 */
class EmpleadoPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tiene($user, 'sueldos.empleados.ver');
    }

    public function view(User $user, Empleado $e): bool
    {
        return $this->tiene($user, 'sueldos.empleados.ver');
    }

    public function create(User $user): bool
    {
        return $this->tiene($user, 'sueldos.empleados.editar');
    }

    public function update(User $user, Empleado $e): bool
    {
        return $this->tiene($user, 'sueldos.empleados.editar');
    }

    public function verBasicos(User $user): bool
    {
        return $this->tiene($user, 'sueldos.basicos.ver');
    }

    public function aprobarBasico(User $user): bool
    {
        return $this->tiene($user, 'sueldos.basicos.aprobar');
    }

    private function tiene(User $user, string $codigo): bool
    {
        return $user->erpPerfil?->tienePermiso($codigo) ?? false;
    }
}
