<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.47 — Conciliación en lote N:M (caso URBANO) + estados nuevos.
 *
 * NOTA CRÍTICA: el schema_v147_conciliacion_lotes.sql del addendum trae ALTERs
 * de `estado` con enums idealizados que NO matchean la realidad (usa
 * AUTO_ETIQUETADO inexistente y enums de facturas con BORRADOR/ANULADA que
 * borrarían CONTROLADA/ANULADA_POR_NC, etc.). Acá los ALTER se hacen
 * PRESERVANDO los valores reales y sólo SUMANDO los nuevos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -- 3 tablas N:M --
        if (! Schema::hasTable('erp_conciliacion_lotes')) {
            Schema::create('erp_conciliacion_lotes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('codigo', 30)->unique();
                $t->unsignedBigInteger('auxiliar_id');
                $t->unsignedBigInteger('cuenta_bancaria_id');
                $t->date('fecha');
                $t->decimal('monto_total', 18, 2);
                $t->enum('signo', ['+', '-']);
                $t->enum('estado', ['BORRADOR', 'CONFIRMADO', 'REVERTIDO'])->default('BORRADOR');
                $t->text('observaciones')->nullable();
                $t->unsignedBigInteger('asiento_id')->nullable();
                $t->string('motivo_diferencia', 255)->nullable();
                $t->unsignedBigInteger('cuenta_ajuste_id')->nullable();
                $t->string('motivo_reversion', 255)->nullable();
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('confirmed_by')->nullable();
                $t->unsignedBigInteger('reverted_by')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamp('reverted_at')->nullable();
                $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->index(['auxiliar_id', 'estado'], 'idx_lotes_aux_estado');
                $t->index('fecha', 'idx_lotes_fecha');
                $t->index('estado', 'idx_lotes_estado');
            });
        }
        if (! Schema::hasTable('erp_conciliacion_lotes_movimientos')) {
            Schema::create('erp_conciliacion_lotes_movimientos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('lote_id');
                $t->unsignedBigInteger('movimiento_bancario_id');
                $t->decimal('monto', 18, 2);
                $t->foreign('lote_id')->references('id')->on('erp_conciliacion_lotes')->cascadeOnDelete();
                $t->unique('movimiento_bancario_id', 'uniq_mov_en_un_lote');
            });
        }
        if (! Schema::hasTable('erp_conciliacion_lotes_facturas')) {
            Schema::create('erp_conciliacion_lotes_facturas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('lote_id');
                $t->unsignedBigInteger('factura_id');
                $t->enum('factura_tipo', ['VENTA', 'COMPRA']);
                $t->decimal('monto_imputado', 18, 2);
                $t->foreign('lote_id')->references('id')->on('erp_conciliacion_lotes')->cascadeOnDelete();
                $t->unique(['lote_id', 'factura_id', 'factura_tipo'], 'uniq_lote_factura');
                $t->index(['factura_id', 'factura_tipo'], 'idx_factura_tipo');
            });
        }

        // -- Estados nuevos (PRESERVANDO los reales) --
        DB::statement("ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
            'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO','CONCILIADO','CONCILIADO_MANUAL','IGNORADO','EN_LOTE','CONFIRMADO_EN_LOTE'
        ) NOT NULL DEFAULT 'PENDIENTE'");

        DB::statement("ALTER TABLE erp_facturas_venta MODIFY COLUMN estado ENUM(
            'PREPARADA','EMITIDA','CONTROLADA','COBRO_PARCIAL','COBRADA','ANULADA_POR_NC','RECHAZADA','EMISION_FALLIDA','IMPUTADA_EN_LOTE'
        ) NOT NULL DEFAULT 'PREPARADA'");

        DB::statement("ALTER TABLE erp_facturas_compra MODIFY COLUMN estado ENUM(
            'RECIBIDA','CONTROLADA','OBSERVADA','PAGO_PARCIAL','PAGADA','ANULADA_POR_NC','RECHAZADA','IMPUTADA_EN_LOTE'
        ) NOT NULL DEFAULT 'RECIBIDA'");

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_conciliacion_lotes_facturas');
        Schema::dropIfExists('erp_conciliacion_lotes_movimientos');
        Schema::dropIfExists('erp_conciliacion_lotes');
        // Los MODIFY de estado no se revierten (podrían existir filas con los nuevos valores).
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'conciliacion.lotes.administrar', 'modulo' => 'tesoreria', 'entidad' => 'conciliacion_lotes', 'accion' => 'administrar', 'descripcion' => 'Crear/confirmar/revertir lotes de conciliación N:M.', 'sensible' => 1],
            ['codigo' => 'contabilidad.pendientes.reclasificar', 'modulo' => 'contabilidad', 'entidad' => 'pendientes_identificar', 'accion' => 'reclasificar', 'descripcion' => 'Reclasificar movimientos de la cuenta puente 1.1.6.99.', 'sensible' => 1],
        ];
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        foreach ($permisos as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
            $pid = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($superAdminId && $pid) {
                DB::table('erp_rol_permiso')->updateOrInsert(['rol_id' => $superAdminId, 'permiso_id' => $pid], []);
            }
        }
    }
};
