<?php

namespace App\Erp\Observers;

use App\Erp\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer genérico que emite entradas a erp_audit_log en cada evento
 * de modificación de entidades críticas del ERP (SPEC_01 §6 / RN-7).
 */
class AuditableObserver
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->log('CREATE', $model, datosDespues: $this->snapshot($model));
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        if (empty($dirty)) {
            return;
        }
        $original = [];
        foreach (array_keys($dirty) as $key) {
            $original[$key] = $model->getOriginal($key);
        }

        $this->logger->log('UPDATE', $model, datosAntes: $original, datosDespues: $dirty);
    }

    public function deleted(Model $model): void
    {
        // Soft-deletes también entran acá porque Eloquent los emite.
        $this->logger->log('DELETE', $model, datosAntes: $this->snapshot($model));
    }

    /**
     * Serialización estable: solo los atributos persistidos, sin `timestamps`
     * por defecto para no inflar el log (se puede revertir si hace falta).
     */
    private function snapshot(Model $model): array
    {
        $attrs = $model->getAttributes();
        unset($attrs['created_at'], $attrs['updated_at']);

        return $attrs;
    }
}
