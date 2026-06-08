<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.44 — Módulo control facturas (PDF → WSCDC + APOC).
 *
 * Schema:
 *  - erp_control_facturas_validaciones: 1 fila por intento de validación.
 *  - erp_control_facturas_alertas: alertas auto generadas (INVALIDA / APOC / etc).
 *
 * Permisos:
 *  - control_facturas.usar     — operador (sube + valida + ve historial propio)
 *  - control_facturas.ver_todo — supervisor (ve historial completo)
 *  - control_facturas.admin    — super_admin (borrar, configurar)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_control_facturas_validaciones')) {
            Schema::create('erp_control_facturas_validaciones', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');

                $t->string('archivo_nombre', 255);
                $t->string('archivo_path', 500);
                $t->unsignedInteger('archivo_size_bytes');
                $t->string('archivo_hash_sha256', 64);

                $t->enum('metodo_extraccion', ['QR', 'OCR', 'MIXTO', 'FALLO']);
                $t->boolean('qr_detectado')->default(false);
                $t->boolean('ocr_aplicado')->default(false);

                $t->json('datos_extraidos');

                $t->boolean('wscdc_consultado')->default(false);
                $t->enum('wscdc_resultado', ['A', 'R', 'O', 'ERROR'])->nullable();
                $t->text('wscdc_obs')->nullable();
                $t->json('wscdc_response_raw')->nullable();
                $t->dateTime('wscdc_fecha_consulta')->nullable();

                $t->boolean('apoc_consultado')->default(false);
                $t->enum('apoc_estado', ['NO_APOC', 'EN_APOC', 'ERROR'])->nullable();
                $t->text('apoc_motivo')->nullable();
                $t->dateTime('apoc_fecha_consulta')->nullable();

                $t->enum('resultado_global', ['VALIDA', 'INVALIDA', 'APOCRIFA', 'ERROR', 'NO_PROCESABLE']);
                $t->enum('nivel_confianza', ['ALTO', 'MEDIO', 'BAJO']);

                $t->unsignedBigInteger('validado_por_user_id');
                $t->enum('estado_seguimiento', ['PENDIENTE_REVISION', 'REVISADA_OK', 'REVISADA_DESCARTADA', 'ESCALADA'])
                  ->default('PENDIENTE_REVISION');
                $t->text('observaciones_operador')->nullable();
                $t->dateTime('fecha_revision')->nullable();
                $t->unsignedBigInteger('revisada_por_user_id')->nullable();

                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $t->index(['validado_por_user_id', 'created_at'], 'idx_ctlf_user_fecha');
                $t->index('resultado_global', 'idx_ctlf_resultado');
                $t->index('archivo_hash_sha256', 'idx_ctlf_hash');
                $t->index('estado_seguimiento', 'idx_ctlf_seguimiento');
            });
        }

        if (! Schema::hasTable('erp_control_facturas_alertas')) {
            Schema::create('erp_control_facturas_alertas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('validacion_id');
                $t->enum('tipo_alerta', ['FACTURA_INVALIDA', 'CUIT_APOC', 'IMPORTE_SOSPECHOSO', 'COMPROBANTE_DUPLICADO']);
                $t->enum('severidad', ['BAJA', 'MEDIA', 'ALTA', 'CRITICA']);
                $t->text('mensaje');
                $t->boolean('leida')->default(false);
                $t->timestamp('created_at')->useCurrent();
                $t->index('leida', 'idx_ctlfa_leida');
                $t->index('validacion_id', 'idx_ctlfa_validacion');
                $t->foreign('validacion_id', 'fk_ctlfa_validacion')
                  ->references('id')->on('erp_control_facturas_validaciones')
                  ->cascadeOnDelete();
            });
        }

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_control_facturas_alertas');
        Schema::dropIfExists('erp_control_facturas_validaciones');
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'control_facturas.usar', 'modulo' => 'control_facturas', 'entidad' => 'validaciones', 'accion' => 'usar',
             'descripcion' => 'Validar PDFs de facturas recibidas (subir + ver resultado + ver historial propio).', 'sensible' => 0],
            ['codigo' => 'control_facturas.ver_todo', 'modulo' => 'control_facturas', 'entidad' => 'validaciones', 'accion' => 'ver',
             'descripcion' => 'Ver historial completo del módulo de control de facturas.', 'sensible' => 0],
            ['codigo' => 'control_facturas.admin', 'modulo' => 'control_facturas', 'entidad' => 'validaciones', 'accion' => 'admin',
             'descripcion' => 'Borrar entradas + configurar el módulo de control de facturas.', 'sensible' => 1],
        ];
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        foreach ($permisos as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
            $pid = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($superAdminId && $pid) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $superAdminId, 'permiso_id' => $pid], [],
                );
            }
        }
    }
};
