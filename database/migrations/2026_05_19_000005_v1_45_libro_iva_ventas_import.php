<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.45 — Importador del Libro IVA Ventas (espejo del v1.9 Compras).
 *
 * Crea la tabla de tracking de archivos importados + agrega `import_id` a
 * `erp_facturas_venta` para vincular cada factura con su import de origen.
 *
 * Idempotencia: UNIQUE (empresa_id, archivo_hash). Mismo CSV no se reprocesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_libros_iva_ventas_import', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('empresa_id');
            $table->string('archivo_nombre', 255);
            $table->char('archivo_hash', 64);
            $table->string('encoding_detectado', 20)->nullable();
            $table->char('periodo_afip', 6)->nullable()->comment('YYYYMM detectado del nombre de archivo');
            $table->unsignedBigInteger('periodo_imputacion_id');
            $table->unsignedInteger('filas_totales')->default(0);
            $table->unsignedInteger('filas_ok')->default(0);
            $table->unsignedInteger('filas_skipped')->default(0);
            $table->unsignedInteger('filas_error')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->json('errores_detalle')->nullable();
            $table->json('warnings_detalle')->nullable();
            $table->json('clientes_no_mapeados')->nullable();
            $table->unsignedInteger('clientes_creados')->default(0);
            $table->unsignedBigInteger('importado_por');
            $table->dateTime('importado_at')->useCurrent();
            $table->enum('estado', [
                'PROCESANDO', 'COMPLETO', 'OK_CON_WARNINGS', 'ERROR_TOTAL',
            ])->default('PROCESANDO');

            $table->unique(['empresa_id', 'archivo_hash'], 'uq_empresa_hash_ventas');
            $table->index('periodo_imputacion_id', 'idx_periodo_imputacion_ventas');
            $table->index('importado_at', 'idx_importado_at_ventas');

            $table->foreign('empresa_id', 'fk_imp_v_empresa')->references('id')->on('erp_empresas');
            $table->foreign('periodo_imputacion_id', 'fk_imp_v_periodo')->references('id')->on('erp_periodos');
            $table->foreign('importado_por', 'fk_imp_v_user')->references('id')->on('users');
        });

        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->unsignedBigInteger('import_id')->nullable()->after('origen');
            $table->index('import_id', 'idx_fv_import_id');
            $table->foreign('import_id', 'fk_fv_import')
                ->references('id')->on('erp_libros_iva_ventas_import')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('erp_facturas_venta', function (Blueprint $table) {
            $table->dropForeign('fk_fv_import');
            $table->dropIndex('idx_fv_import_id');
            $table->dropColumn('import_id');
        });
        Schema::dropIfExists('erp_libros_iva_ventas_import');
    }
};
