<?php

namespace App\Erp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auxiliar extends Model
{
    protected $table = 'erp_auxiliares';

    protected $fillable = [
        'empresa_id', 'tipo', 'tabla_ref', 'id_ref',
        'codigo', 'nombre', 'cuit', 'activo',
        'cuenta_contable_default_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * ADDENDUM v1.10 — mapeo tipo → código de cuenta default sugerido. Solo
     * aplica a tipos operativos comunes (factura/pago). Otros (Socio,
     * Vehiculo, Bien, Sucursal, Colocacion, Organismo) requieren asignación
     * manual del operador.
     */
    public const CUENTA_DEFAULT_POR_TIPO = [
        'Cliente'      => '1.1.4.01',
        'Distribuidor' => '2.1.1.03',
        'Proveedor'    => '2.1.1.01',
        'Empleado'     => '2.1.2.01',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoAsiento::class, 'auxiliar_id');
    }

    public function cuentaDefault(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_default_id');
    }
}
