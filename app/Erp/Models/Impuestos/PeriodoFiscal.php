<?php

namespace App\Erp\Models\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PeriodoFiscal extends Model
{
    protected $table = 'erp_periodos_fiscales';

    protected $fillable = [
        'empresa_id', 'impuesto', 'anio', 'mes', 'ejercicio_id',
        'estado', 'fecha_vencimiento', 'fecha_presentacion',
        'nro_tramite', 'acuse_path', 'observaciones',
        'rectifica_a_id',
        'revisor_user_id', 'aprobado_user_id', 'aprobado_at',
        'presentado_user_id', 'presentado_at',
    ];

    protected $casts = [
        'fecha_vencimiento'  => 'date',
        'fecha_presentacion' => 'date',
        'aprobado_at'        => 'datetime',
        'presentado_at'      => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }

    public function rectificaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifica_a_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisor_user_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_user_id');
    }

    public function presentador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presentado_user_id');
    }

    public function libroIvaVentas(): HasOne
    {
        return $this->hasOne(LibroIvaVentasPeriodo::class, 'periodo_id');
    }

    public function libroIvaCompras(): HasOne
    {
        return $this->hasOne(LibroIvaComprasPeriodo::class, 'periodo_id');
    }

    public function esEditable(): bool
    {
        return in_array($this->estado, ['ABIERTO', 'EN_REVISION'], true);
    }

    public function esCerrado(): bool
    {
        return in_array($this->estado, ['PRESENTADO', 'CERRADO'], true);
    }
}
