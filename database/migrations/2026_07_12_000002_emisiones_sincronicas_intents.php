<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría 2026-07-12 bug #2 — registro de intención para la emisión
 * SÍNCRONA de CAE (EmisorFacturaService). Antes el service emitía sin dejar
 * rastro local: un timeout post-autorización de AFIP dejaba un CAE huérfano
 * y el reintento del operador generaba doble CAE.
 *
 * Cada intento queda registrado ANTES de llamar al gateway, con clave de
 * idempotencia estable (fv-sync-{id}) y un snapshot suficiente para
 * persistir la factura si el CAE se recupera por reconciliación.
 *
 * estado: EN_VUELO → OK | ERROR (resultado conocido)
 *                  → VERIFICAR (resultado desconocido: timeout/5xx)
 *                  → DESCARTADA (verificado contra AFIP: no se emitió)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_emisiones_sincronicas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('empresa_id');
            $t->unsignedSmallInteger('tipo_comprobante_id');
            $t->unsignedInteger('pto_vta_numero');
            $t->string('idempotency_key', 64)->unique('uk_emsync_idem');
            $t->char('fingerprint', 64); // hash del contenido del comprobante
            $t->json('snapshot');
            $t->string('estado', 20)->default('EN_VUELO');
            $t->unsignedBigInteger('factura_venta_id')->nullable();
            $t->string('cae', 20)->nullable();
            $t->text('ultimo_error')->nullable();
            $t->timestamps();

            $t->index(['empresa_id', 'tipo_comprobante_id', 'pto_vta_numero', 'estado'], 'idx_emsync_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_emisiones_sincronicas');
    }
};
