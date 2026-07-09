<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento de cheques — desglose completo de la quita.
 *
 * Nuevos conceptos además de intereses/IVA/comisión: sellado, percepción IIBB
 * y otros impuestos; más la entidad donde se descontó (banco/financiera).
 * Cuentas: sellado → 5.5.08 Impuesto de Sellos (nueva), percepción IIBB →
 * 1.1.6.15 Percepciones IIBB Sufridas (agregada), otros → 5.5.07 Otros Impuestos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_cheques_recibidos', 'descuento_entidad')) {
                $t->string('descuento_entidad', 150)->nullable()->after('fecha_acreditacion');
                $t->decimal('descuento_sellado', 18, 2)->nullable()->after('descuento_comision');
                $t->decimal('descuento_percepcion_iibb', 18, 2)->nullable()->after('descuento_sellado');
                $t->decimal('descuento_otros', 18, 2)->nullable()->after('descuento_percepcion_iibb');
            }
        });

        // Cuenta 5.5.08 Impuesto de Sellos (idempotente).
        $padre = DB::table('erp_cuentas_contables')->where('empresa_id', 1)->where('codigo', '5.5')->value('id');
        $existe = DB::table('erp_cuentas_contables')->where('empresa_id', 1)->where('codigo', '5.5.08')->exists();
        if ($padre && ! $existe) {
            DB::table('erp_cuentas_contables')->insert([
                'empresa_id' => 1, 'codigo' => '5.5.08', 'codigo_padre_id' => $padre,
                'nivel' => 4, 'nombre' => 'Impuesto de Sellos', 'tipo' => 'RN',
                'rubro_ec' => 'Impuestos', 'imputable' => 1, 'moneda' => 'ARS',
                'admite_cc' => 0, 'admite_auxiliar' => 0, 'saldo_normal' => 'DEUDOR',
                'regularizadora' => 0, 'activo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            foreach (['descuento_entidad', 'descuento_sellado', 'descuento_percepcion_iibb', 'descuento_otros'] as $c) {
                if (Schema::hasColumn('erp_cheques_recibidos', $c)) $t->dropColumn($c);
            }
        });
        // La cuenta 5.5.08 se conserva (puede tener movimientos).
    }
};
