<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.44 — Activar las 24 jurisdicciones IIBB.
 *
 * El seed original había dejado solo CABA (901) y Buenos Aires (902) como
 * `activa=1`. El resto del padrón AFIP (903 Catamarca a 924 Tucumán) estaba
 * `activa=0` y no aparecía en los selects del frontend.
 *
 * En la práctica el cliente factura a empresas de cualquier jurisdicción
 * (no estamos atados a una región), así que todas deberían poder elegirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_iibb_jurisdicciones')
            ->whereBetween('codigo', ['901', '924'])
            ->update(['activa' => 1]);
    }

    public function down(): void
    {
        // No revertir: dejar activas no es destructivo y el down podría
        // bloquear facturas ya cargadas con jurisdicciones del 903-924.
    }
};
