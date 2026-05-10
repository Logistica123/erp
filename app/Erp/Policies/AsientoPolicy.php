<?php

namespace App\Erp\Policies;

use App\Erp\Models\Asiento;
use App\Models\User;

class AsientoPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->tienePermiso($user, 'contabilidad.asientos.ver');
    }

    public function view(User $user, Asiento $asiento): bool
    {
        if (! $this->mismaEmpresa($user, $asiento->empresa_id)) {
            return false;
        }

        return $this->tienePermiso($user, 'contabilidad.asientos.ver');
    }

    public function create(User $user): bool
    {
        return $this->tienePermiso($user, 'contabilidad.asientos.crear');
    }

    public function update(User $user, Asiento $asiento): bool
    {
        if (! $this->mismaEmpresa($user, $asiento->empresa_id)) {
            return false;
        }
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return false;
        }

        return $this->tienePermiso($user, 'contabilidad.asientos.editar');
    }

    public function contabilizar(User $user, Asiento $asiento): bool
    {
        if (! $this->mismaEmpresa($user, $asiento->empresa_id)) {
            return false;
        }

        return $this->tienePermiso($user, 'contabilidad.asientos.contabilizar');
    }

    public function anular(User $user, Asiento $asiento): bool
    {
        if (! $this->mismaEmpresa($user, $asiento->empresa_id)) {
            return false;
        }

        return $this->tienePermiso($user, 'contabilidad.asientos.anular');
    }

    public function delete(User $user, Asiento $asiento): bool
    {
        if (! $this->mismaEmpresa($user, $asiento->empresa_id)) {
            return false;
        }
        if ($asiento->estado !== Asiento::ESTADO_BORRADOR) {
            return false;
        }

        // v1.15 Sprint M — el addendum pide permiso dedicado
        // `contabilidad.asientos.eliminar_borrador`. Aceptamos también
        // `contabilidad.asientos.editar` por backward compat.
        return $this->tienePermiso($user, 'contabilidad.asientos.eliminar_borrador')
            || $this->tienePermiso($user, 'contabilidad.asientos.editar');
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
