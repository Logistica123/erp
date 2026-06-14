<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.45 §3 — Schema delta del módulo de auto-conciliación.
 *
 * Nota de nombres: el addendum referencia `erp_reglas_conciliacion` y
 * `erp_extractos_movimientos`, pero las tablas reales del ERP son
 * `erp_conciliacion_reglas` y `erp_movimientos_bancarios`. Acá se usan las
 * reales. `cuenta_contable_id` ya existe en erp_conciliacion_reglas.
 *
 * El enum estado real de erp_movimientos_bancarios es
 * (PENDIENTE,ETIQUETADO,CONCILIADO,IGNORADO) — NO tiene AUTO_ETIQUETADO.
 * Sumamos MATCH_AUTO/CONFIRMADO/REVERTIDO/CONCILIADO_MANUAL preservando los 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 3.1 — erp_conciliacion_reglas: columnas de matching dinámico.
        Schema::table('erp_conciliacion_reglas', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'cuenta_contable_modo')) {
                $t->enum('cuenta_contable_modo', ['FIJO', 'DINAMICO_POR_AUXILIAR', 'SIN_CUENTA_TRANSFERENCIA_INTERNA'])
                  ->default('FIJO')->after('cuenta_contable_id');
            }
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'cuit_extractor_regex')) {
                $t->string('cuit_extractor_regex', 500)->nullable()->after('cuenta_contable_modo');
            }
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'matching_auto_factura')) {
                $t->boolean('matching_auto_factura')->default(false)->after('cuit_extractor_regex');
            }
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'tipo_auxiliar')) {
                $t->enum('tipo_auxiliar', ['CLIENTE', 'PROVEEDOR', 'DISTRIBUIDOR', 'EMPLEADO', 'OTRO'])
                  ->nullable()->after('matching_auto_factura');
            }
        });

        // 3.2 — erp_movimientos_bancarios: estados + columnas de imputación auto.
        DB::statement("ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
            'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO','CONCILIADO','CONCILIADO_MANUAL','IGNORADO'
        ) NOT NULL DEFAULT 'PENDIENTE'");

        Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'factura_imputada_id')) {
                $t->unsignedBigInteger('factura_imputada_id')->nullable()->after('asiento_id');
            }
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'factura_imputada_tipo')) {
                $t->enum('factura_imputada_tipo', ['VENTA', 'COMPRA'])->nullable()->after('factura_imputada_id');
            }
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'imputacion_confianza')) {
                $t->decimal('imputacion_confianza', 5, 2)->nullable()->after('factura_imputada_tipo');
            }
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'cuit_extractado')) {
                $t->string('cuit_extractado', 13)->nullable()->after('imputacion_confianza');
            }
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'auxiliar_resuelto_id')) {
                $t->unsignedBigInteger('auxiliar_resuelto_id')->nullable()->after('cuit_extractado');
                $t->index('auxiliar_resuelto_id', 'idx_mb_auxiliar_resuelto');
            }
        });

        // 3.3 — tabla de auditoría de imputaciones.
        if (! Schema::hasTable('erp_extractos_imputaciones_audit')) {
            Schema::create('erp_extractos_imputaciones_audit', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('movimiento_id');
                $t->enum('accion', ['AUTO_IMPUTAR', 'MODIFICAR', 'CONFIRMAR', 'REVERTIR']);
                $t->unsignedBigInteger('user_id')->nullable()->comment('NULL si fue AUTO_IMPUTAR por el sistema');
                $t->string('estado_previo', 30)->nullable();
                $t->string('estado_posterior', 30);
                $t->unsignedBigInteger('factura_imputada_previa_id')->nullable();
                $t->unsignedBigInteger('factura_imputada_nueva_id')->nullable();
                $t->unsignedBigInteger('asiento_previo_id')->nullable();
                $t->unsignedBigInteger('asiento_nuevo_id')->nullable();
                $t->text('motivo')->nullable();
                $t->json('snapshot_completo');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['movimiento_id', 'created_at'], 'idx_imp_audit_mov');
                $t->index('accion', 'idx_imp_audit_accion');
                $t->foreign('movimiento_id', 'fk_imp_audit_mov')
                  ->references('id')->on('erp_movimientos_bancarios')->cascadeOnDelete();
            });
        }

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_extractos_imputaciones_audit');
        Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
            foreach (['factura_imputada_id', 'factura_imputada_tipo', 'imputacion_confianza', 'cuit_extractado', 'auxiliar_resuelto_id'] as $c) {
                if (Schema::hasColumn('erp_movimientos_bancarios', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('erp_conciliacion_reglas', function (Blueprint $t) {
            foreach (['cuenta_contable_modo', 'cuit_extractor_regex', 'matching_auto_factura', 'tipo_auxiliar'] as $c) {
                if (Schema::hasColumn('erp_conciliacion_reglas', $c)) $t->dropColumn($c);
            }
        });
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'extractos.imputaciones.modificar', 'modulo' => 'tesoreria', 'entidad' => 'extractos_imputaciones', 'accion' => 'modificar', 'descripcion' => 'Modificar imputación auto (MATCH_AUTO).', 'sensible' => 0],
            ['codigo' => 'extractos.imputaciones.confirmar', 'modulo' => 'tesoreria', 'entidad' => 'extractos_imputaciones', 'accion' => 'confirmar', 'descripcion' => 'Confirmar imputación auto (MATCH_AUTO → CONFIRMADO).', 'sensible' => 0],
            ['codigo' => 'extractos.imputaciones.revertir', 'modulo' => 'tesoreria', 'entidad' => 'extractos_imputaciones', 'accion' => 'revertir', 'descripcion' => 'Revertir imputación auto con motivo (anula asiento + restaura saldo).', 'sensible' => 1],
            ['codigo' => 'extractos.imputaciones.revertir_confirmada', 'modulo' => 'tesoreria', 'entidad' => 'extractos_imputaciones', 'accion' => 'revertir_confirmada', 'descripcion' => 'Revertir una imputación ya CONFIRMADA (control extra).', 'sensible' => 1],
            ['codigo' => 'reglas_conciliacion.administrar', 'modulo' => 'tesoreria', 'entidad' => 'reglas_conciliacion', 'accion' => 'administrar', 'descripcion' => 'ABM de reglas de conciliación.', 'sensible' => 1],
            ['codigo' => 'reglas_conciliacion.asignar_cuenta', 'modulo' => 'tesoreria', 'entidad' => 'reglas_conciliacion', 'accion' => 'asignar_cuenta', 'descripcion' => 'Asignar cuenta contable a reglas de conciliación.', 'sensible' => 0],
            ['codigo' => 'contabilidad.iiddycc.reclasificar', 'modulo' => 'contabilidad', 'entidad' => 'iiddycc', 'accion' => 'reclasificar', 'descripcion' => 'Generar asiento de reclasificación Imp Ley 25413 (gasto → crédito fiscal).', 'sensible' => 1],
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
