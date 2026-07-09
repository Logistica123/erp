<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.52 — Carga de Saldo Inicial (Cajas y Bancos).
 *
 * Tabla de trazabilidad de cargas iniciales. El asiento se genera con el
 * sistema estándar (AsientoService). Además del cuenta_bancaria_id del spec,
 * agregamos caja_id: las cajas físicas viven en erp_cajas y su saldo_actual
 * materializado es el que lee el arqueo (v1.42).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_cargas_saldo_inicial', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('cuenta_contable_destino_id')->comment('Cuenta A / Caja y Bancos / imputable que recibe el saldo');
            $table->unsignedBigInteger('cuenta_contable_contrapartida_id')->comment('Contrapartida patrimonial, default 3.3.01');
            $table->unsignedInteger('cuenta_bancaria_id')->nullable()->comment('Match unívoco cuenta contable → banco (refresh saldo_actual)');
            $table->unsignedInteger('caja_id')->nullable()->comment('Match unívoco cuenta contable → caja física (refresh saldo_actual, arqueos)');
            $table->decimal('monto', 18, 2);
            $table->date('fecha')->comment('Fecha del asiento; validada contra período abierto por AsientoService');
            $table->enum('motivo_tipo', ['APERTURA_EJERCICIO', 'PUESTA_MARCHA_MODULO', 'REGULARIZACION_ESTUDIO', 'OTRO']);
            $table->string('motivo_observacion', 500)->nullable()->comment('Obligatoria (min 10) si motivo_tipo=OTRO');
            $table->unsignedBigInteger('asiento_id');
            $table->enum('estado', ['ACTIVO', 'REVERTIDO'])->default('ACTIVO');
            $table->unsignedBigInteger('asiento_reversa_id')->nullable();
            $table->string('motivo_reversa', 500)->nullable();
            $table->timestamp('revertido_at')->nullable();
            $table->unsignedBigInteger('revertido_by')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('empresa_id', 'fk_csi_empresa')->references('id')->on('erp_empresas');
            $table->foreign('cuenta_contable_destino_id', 'fk_csi_cta_destino')->references('id')->on('erp_cuentas_contables');
            $table->foreign('cuenta_contable_contrapartida_id', 'fk_csi_cta_contrapartida')->references('id')->on('erp_cuentas_contables');
            $table->foreign('cuenta_bancaria_id', 'fk_csi_cuenta_bancaria')->references('id')->on('erp_cuentas_bancarias');
            $table->foreign('caja_id', 'fk_csi_caja')->references('id')->on('erp_cajas');
            $table->foreign('asiento_id', 'fk_csi_asiento')->references('id')->on('erp_asientos');
            $table->foreign('asiento_reversa_id', 'fk_csi_asiento_reversa')->references('id')->on('erp_asientos');
            $table->foreign('created_by', 'fk_csi_created_by')->references('id')->on('users');
            $table->foreign('revertido_by', 'fk_csi_revertido_by')->references('id')->on('users');

            $table->index('cuenta_contable_destino_id', 'idx_csi_cuenta_destino');
            $table->index('estado', 'idx_csi_estado');
            $table->index('fecha', 'idx_csi_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_cargas_saldo_inicial');
    }
};
