<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completar clientes de la plataforma desde el ERP.
 *
 * Los operadores crean clientes "de fantasía" en DistriApp (basepersonal) para
 * no frenar la venta; luego el ERP los completa con los datos fiscales reales
 * (razón social, CUIT, domicilio, condición IVA) y escribe esa corrección de
 * vuelta en la plataforma (clientes + tax_profiles) SIN romper referencias
 * (siempre UPDATE por id, nunca cambia id/codigo).
 *
 * Esta migración solo toca el lado ERP: agrega los campos fiscales que faltaban
 * en erp_auxiliares + un timestamp de sincronización + el permiso del módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_auxiliares', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_auxiliares', 'domicilio_calle')) {
                $t->string('domicilio_calle', 255)->nullable()->after('razon_social_normalizada');
                $t->string('domicilio_nro', 20)->nullable()->after('domicilio_calle');
                $t->string('domicilio_piso', 20)->nullable()->after('domicilio_nro');
                $t->string('domicilio_depto', 20)->nullable()->after('domicilio_piso');
                $t->string('localidad', 255)->nullable()->after('domicilio_depto');
                $t->string('provincia', 120)->nullable()->after('localidad');
                $t->string('cod_postal', 20)->nullable()->after('provincia');
                $t->unsignedTinyInteger('condicion_iva_id')->nullable()->after('cod_postal');
                $t->dateTime('sincronizado_plataforma_at')->nullable()->after('condicion_iva_id');
            }
        });

        // Permiso del módulo (sensible=1: escribe en la plataforma).
        DB::statement(
            "INSERT IGNORE INTO erp_permisos (codigo, modulo, entidad, accion, descripcion, sensible)
             VALUES ('integracion.clientes.completar', 'integracion', 'clientes', 'completar',
                     'Completar datos fiscales de clientes de la plataforma (escribe en DistriApp)', 1)"
        );
        // super_admin (1), contador (2), facturador (4).
        DB::statement(
            "INSERT IGNORE INTO erp_rol_permiso (rol_id, permiso_id)
             SELECT r.rol_id, p.id
               FROM (SELECT 1 AS rol_id UNION SELECT 2 UNION SELECT 4) r
               JOIN erp_permisos p ON p.codigo = 'integracion.clientes.completar'"
        );
    }

    public function down(): void
    {
        Schema::table('erp_auxiliares', function (Blueprint $t) {
            foreach ([
                'domicilio_calle', 'domicilio_nro', 'domicilio_piso', 'domicilio_depto',
                'localidad', 'provincia', 'cod_postal', 'condicion_iva_id', 'sincronizado_plataforma_at',
            ] as $col) {
                if (Schema::hasColumn('erp_auxiliares', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (SELECT id FROM erp_permisos WHERE codigo='integracion.clientes.completar')");
        DB::statement("DELETE FROM erp_permisos WHERE codigo='integracion.clientes.completar'");
    }
};
