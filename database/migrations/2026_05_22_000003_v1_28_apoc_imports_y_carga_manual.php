<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.28 — Consulta APOC AFIP en imports del Libro IVA Compras + carga manual.
 *
 * Cambios:
 *  1. ALTER erp_facturas_compra: 4 columnas para tracking del estado APOC al
 *     momento de cargar la factura + override del operador (con motivo).
 *  2. Permisos nuevos:
 *      - compras.facturas.cargar_manual_override_apoc (sensible=1)
 *      - compras.libro_iva.import_override_apoc (sensible=1)
 *  3. Cache APOC ya existe: reutilizamos `arca_padron_cache` (PadronCache model).
 *     No creamos `erp_apoc_cache` (D-28-3: una sola fuente entre ERP y DistriApp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_facturas_compra', 'apoc_estado_al_cargar')) {
                $table->string('apoc_estado_al_cargar', 40)->nullable()
                    ->comment('Estado APOC al momento de cargar la factura (ACTIVO/INACTIVO/etc)');
            }
            if (! Schema::hasColumn('erp_facturas_compra', 'apoc_override')) {
                $table->boolean('apoc_override')->default(false)
                    ->comment('TRUE si el operador hizo override de un CUIT no ACTIVO');
            }
            if (! Schema::hasColumn('erp_facturas_compra', 'apoc_override_motivo')) {
                $table->text('apoc_override_motivo')->nullable();
            }
            if (! Schema::hasColumn('erp_facturas_compra', 'apoc_override_por_user_id')) {
                $table->unsignedBigInteger('apoc_override_por_user_id')->nullable();
            }
        });

        // Índice solo si las columnas se crearon (idempotente con re-runs).
        $hasIndex = collect(DB::select("SHOW INDEX FROM erp_facturas_compra WHERE Key_name = 'idx_apoc_override'"))->count() > 0;
        if (! $hasIndex) {
            DB::statement('CREATE INDEX idx_apoc_override ON erp_facturas_compra (apoc_override)');
        }

        $perms = [
            ['codigo' => 'compras.facturas.cargar_manual_override_apoc',
             'modulo' => 'compras', 'entidad' => 'facturas', 'accion' => 'override_apoc',
             'descripcion' => 'Permite cargar facturas manuales con CUIT no activo en padrón APOC AFIP, con motivo obligatorio.',
             'sensible' => 1],
            ['codigo' => 'compras.libro_iva.import_override_apoc',
             'modulo' => 'compras', 'entidad' => 'libro_iva', 'accion' => 'import_override_apoc',
             'descripcion' => 'Permite importar Libro IVA Compras con CUITs no activos, con motivo global obligatorio.',
             'sensible' => 1],
        ];
        foreach ($perms as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
        }

        $rolesIds = DB::table('erp_roles')
            ->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
        $permIds = DB::table('erp_permisos')
            ->whereIn('codigo', [
                'compras.facturas.cargar_manual_override_apoc',
                'compras.libro_iva.import_override_apoc',
            ])->pluck('id');
        foreach ($rolesIds as $rolId) {
            foreach ($permIds as $permisoId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $rolId, 'permiso_id' => $permisoId], [],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('erp_rol_permiso')->whereIn('permiso_id', function ($q) {
            $q->select('id')->from('erp_permisos')->whereIn('codigo', [
                'compras.facturas.cargar_manual_override_apoc',
                'compras.libro_iva.import_override_apoc',
            ]);
        })->delete();
        DB::table('erp_permisos')->whereIn('codigo', [
            'compras.facturas.cargar_manual_override_apoc',
            'compras.libro_iva.import_override_apoc',
        ])->delete();

        Schema::table('erp_facturas_compra', function (Blueprint $table) {
            $table->dropIndex('idx_apoc_override');
            $table->dropColumn([
                'apoc_estado_al_cargar', 'apoc_override',
                'apoc_override_motivo', 'apoc_override_por_user_id',
            ]);
        });
    }
};
