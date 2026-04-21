<?php

namespace App\Erp\Policies;

use App\Erp\Models\CuentaContable;
use App\Models\User;

class CuentaContablePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tienePermiso($user, 'contabilidad.cuentas.ver');
    }

    public function view(User $user, CuentaContable $cuenta): bool
    {
        return $this->mismaEmpresa($user, $cuenta->empresa_id)
            && $this->tienePermiso($user, 'contabilidad.cuentas.ver');
    }

    public function create(User $user): bool
    {
        return $this->tienePermiso($user, 'contabilidad.cuentas.editar');
    }

    public function update(User $user, CuentaContable $cuenta): bool
    {
        return $this->mismaEmpresa($user, $cuenta->empresa_id)
            && $this->tienePermiso($user, 'contabilidad.cuentas.editar');
    }

    private function tienePermiso(User $user, string $codigo): bool
    {
        return $user->erpPerfil?->tienePermiso($codigo) ?? false;
    }

    private function mismaEmpresa(User $user, ?int $empresaId): bool
    {
        return $user->erpPerfil?->empresa_id === $empresaId;
    }
}
