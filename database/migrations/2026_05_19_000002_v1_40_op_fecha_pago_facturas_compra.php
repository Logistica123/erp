<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.40 — Columnas OP (orden de pago externa) + fecha de pago.
 *
 * Las 6 columnas extras del importador del Libro IVA Compras (v1.13/v1.14)
 * se amplían a 8 con dos campos de pago que el contador suele tener en su
 * planilla:
 *   - `op_externa` (VARCHAR 50): número/identificador externo de la OP
 *     (Orden de Pago) — texto libre, lo que el contador escriba en el Excel.
 *   - `fecha_pago` (DATE): fecha en la que la factura fue pagada.
 *
 * Se guardan solo para referencia/auditoría (no impactan estado de la
 * factura por ahora). El estado PAGO_PARCIAL/PAGADA sigue derivándose del
 * módulo de Tesorería (órdenes de pago internas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            $table->string('op_externa', 50)->nullable()->after('observaciones');
            $table->date('fecha_pago')->nullable()->after('op_externa');
        });
    }

    public function down(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            $table->dropColumn(['op_externa', 'fecha_pago']);
        });
    }
};
