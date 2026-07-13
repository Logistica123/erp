<?php

namespace App\Erp\Observers;

use App\Erp\Models\Auxiliar;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 — Observer que crea automáticamente un Centro de Costos
 * (`erp_centros_costo` con tipo='CLIENTE') por cada auxiliar tipo='Cliente'
 * recién dado de alta.
 *
 * El centro_costo_id luego se referencia desde `erp_facturas_venta` y
 * `erp_facturas_compra` para reportes de margen por cliente, etc.
 *
 * Idempotente: si ya existe un CC con `auxiliar_id = X`, no hace nada.
 */
class AuxiliarClienteObserver
{
    public function created(Auxiliar $aux): void
    {
        if ($aux->tipo !== 'Cliente') {
            return;
        }
        $this->ensureCentroCosto($aux);
    }

    public function updated(Auxiliar $aux): void
    {
        if ($aux->tipo !== 'Cliente') {
            return;
        }
        // Si el auxiliar pasó a tipo Cliente desde otro tipo, garantizamos
        // el CC; si solo cambió el nombre, sincronizamos el nombre del CC.
        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        if (! $cc) {
            $this->ensureCentroCosto($aux);
            return;
        }
        if ($cc->nombre !== $aux->nombre) {
            DB::table('erp_centros_costo')
                ->where('id', $cc->id)
                ->update(['nombre' => $aux->nombre, 'updated_at' => now()]);
        }
    }

    private function ensureCentroCosto(Auxiliar $aux): void
    {
        // Mini-tanda 2026-07-13 bug 3: lógica extraída a CcCliente para que
        // los caminos de insert crudo (imports, bridge, sync) la compartan.
        \App\Erp\Support\CcCliente::asegurar((int) $aux->id);
    }
}
