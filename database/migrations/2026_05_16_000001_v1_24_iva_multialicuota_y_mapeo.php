<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDENDUM v1.24 — Generador de asientos completo: IVA multi-alícuota +
 * percepciones por tipo + impuestos como gasto + tolerancia de redondeo $1.
 *
 * Bug expuesto post v1.23: 47 errores ASIENTO_DESBALANCEADO — 28 por
 * redondeos AFIP ≤ $1 + 19 por conceptos no imputados (percepciones IIBB,
 * Otros Imp. Nacionales, Impuestos Municipales/Internos/Otros Tributos).
 *
 * DDL en orden:
 *   1. Cuentas nuevas:
 *      - 1.1.6.01 → padre (imputable=0)
 *      - 1.1.6.01.21/.10/.27/.02/.05 → 5 hijas por alícuota IVA
 *      - 1.1.6.15 → Percepciones IIBB Sufridas (agregada, para CSV AFIP)
 *      - 1.1.6.16 → Percepciones Otros Imp. Nacionales
 *      - 5.5.08   → Impuestos Internos
 *   2. erp_facturas_compra: +11 columnas para detallar por alícuota / tipo
 *   3. Tabla erp_configuracion_iva_mapeo: concepto CSV → cuenta contable
 *   4. Seed default del mapeo
 *   5. Permiso contabilidad.iva_mapeo.editar para super_admin + contador
 *
 * Cero movimientos contables sobre las cuentas afectadas (verificado pre-deploy),
 * así que convertir 1.1.6.01 a no imputable es seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        $empresaId = 1;

        // 1.A — Convertir 1.1.6.01 en padre no imputable.
        DB::table('erp_cuentas_contables')
            ->where('codigo', '1.1.6.01')
            ->where('empresa_id', $empresaId)
            ->update([
                'nombre' => 'IVA Crédito Fiscal',
                'imputable' => 0,
                'updated_at' => now(),
            ]);

        $padreIvaCfId = DB::table('erp_cuentas_contables')
            ->where('codigo', '1.1.6.01')->where('empresa_id', $empresaId)
            ->value('id');
        $padreCreditosFiscalesId = DB::table('erp_cuentas_contables')
            ->where('codigo', '1.1.6')->where('empresa_id', $empresaId)
            ->value('id');
        $padreImpuestosId = DB::table('erp_cuentas_contables')
            ->where('codigo', '5.5')->where('empresa_id', $empresaId)
            ->value('id');

        // 1.B — 5 hijas IVA CF por alícuota.
        $hijasIva = [
            ['1.1.6.01.21', 'IVA Crédito Fiscal 21%'],
            ['1.1.6.01.10', 'IVA Crédito Fiscal 10,5%'],
            ['1.1.6.01.27', 'IVA Crédito Fiscal 27%'],
            ['1.1.6.01.02', 'IVA Crédito Fiscal 2,5%'],
            ['1.1.6.01.05', 'IVA Crédito Fiscal 5%'],
        ];
        foreach ($hijasIva as [$codigo, $nombre]) {
            $this->upsertCuenta($empresaId, $codigo, $nombre, $padreIvaCfId, 5, 'A',
                rubro: 'Otros Créditos', saldoNormal: 'DEUDOR');
        }

        // 1.C — Cuentas IIBB agregada y Per Otros Imp Nac.
        $this->upsertCuenta($empresaId, '1.1.6.15', 'Percepciones IIBB Sufridas (agregada)',
            $padreCreditosFiscalesId, 4, 'A', rubro: 'Otros Créditos', saldoNormal: 'DEUDOR');
        $this->upsertCuenta($empresaId, '1.1.6.16', 'Percepciones Otros Imp. Nacionales',
            $padreCreditosFiscalesId, 4, 'A', rubro: 'Otros Créditos', saldoNormal: 'DEUDOR');

        // 1.D — Impuestos Internos (col 18).
        $this->upsertCuenta($empresaId, '5.5.08', 'Impuestos Internos',
            $padreImpuestosId, 4, 'RN', rubro: 'Impuestos', saldoNormal: 'DEUDOR');

        // 2 — erp_facturas_compra: 11 columnas detalladas.
        Schema::table('erp_facturas_compra', function (Blueprint $t) {
            // IVA por alícuota.
            $cols = [
                'imp_iva_21', 'imp_iva_10_5', 'imp_iva_27', 'imp_iva_2_5', 'imp_iva_5',
                'imp_percepciones_iva', 'imp_percepciones_iibb', 'imp_percepciones_otros_nac',
                'imp_municipales', 'imp_internos', 'imp_otros_tributos',
            ];
            foreach ($cols as $c) {
                if (! Schema::hasColumn('erp_facturas_compra', $c)) {
                    $t->decimal($c, 18, 2)->default(0)->after('imp_iva');
                }
            }
        });

        // 3 — Tabla de mapeo.
        if (! Schema::hasTable('erp_configuracion_iva_mapeo')) {
            Schema::create('erp_configuracion_iva_mapeo', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('concepto_csv', 80);
                $t->unsignedBigInteger('cuenta_contable_id');
                $t->string('descripcion', 120);
                $t->boolean('activo')->default(true);
                $t->text('observaciones')->nullable();
                $t->timestamps();

                $t->unique(['empresa_id', 'concepto_csv'], 'uk_iva_map_emp_concepto');
                $t->foreign('empresa_id', 'fk_iva_map_empresa')
                    ->references('id')->on('erp_empresas');
                $t->foreign('cuenta_contable_id', 'fk_iva_map_cuenta')
                    ->references('id')->on('erp_cuentas_contables');
            });
        }

        // 4 — Seed default del mapeo.
        $mapeos = [
            ['iva_credito_21',         '1.1.6.01.21', 'IVA Crédito Fiscal 21%'],
            ['iva_credito_10_5',       '1.1.6.01.10', 'IVA Crédito Fiscal 10,5%'],
            ['iva_credito_27',         '1.1.6.01.27', 'IVA Crédito Fiscal 27%'],
            ['iva_credito_2_5',        '1.1.6.01.02', 'IVA Crédito Fiscal 2,5%'],
            ['iva_credito_5',          '1.1.6.01.05', 'IVA Crédito Fiscal 5%'],
            ['percepciones_iva',       '1.1.6.04',    'Percepciones IVA Sufridas'],
            ['percepciones_iibb',      '1.1.6.15',    'Percepciones IIBB Sufridas (agregada)'],
            ['percepciones_otros_nac', '1.1.6.16',    'Percepciones Otros Imp. Nacionales'],
            ['imp_municipales',        '5.5.04',      'Tasas Municipales'],
            ['imp_internos',           '5.5.08',      'Impuestos Internos'],
            ['otros_tributos',         '5.5.07',      'Otros Impuestos'],
        ];
        foreach ($mapeos as [$concepto, $codigo, $descripcion]) {
            $cuentaId = DB::table('erp_cuentas_contables')
                ->where('codigo', $codigo)->where('empresa_id', $empresaId)
                ->value('id');
            if (! $cuentaId) {
                throw new \RuntimeException("Seed v1.24: cuenta {$codigo} no existe");
            }
            DB::table('erp_configuracion_iva_mapeo')->updateOrInsert(
                ['empresa_id' => $empresaId, 'concepto_csv' => $concepto],
                [
                    'cuenta_contable_id' => $cuentaId,
                    'descripcion' => $descripcion,
                    'activo' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        // 5 — Permiso para editar el mapeo.
        if (! DB::table('erp_permisos')->where('codigo', 'contabilidad.iva_mapeo.editar')->exists()) {
            DB::table('erp_permisos')->insert([
                'codigo' => 'contabilidad.iva_mapeo.editar',
                'modulo' => 'contabilidad',
                'entidad' => 'configuracion_iva_mapeo',
                'accion' => 'editar',
                'descripcion' => 'Permite editar el mapeo de conceptos AFIP a cuentas contables (importador Libro IVA Compras).',
                'sensible' => 1,
            ]);
        }
        $permId = DB::table('erp_permisos')->where('codigo', 'contabilidad.iva_mapeo.editar')->value('id');
        $roles = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
        foreach ($roles as $rolId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $rolId, 'permiso_id' => $permId],
                ['rol_id' => $rolId, 'permiso_id' => $permId],
            );
        }
    }

    public function down(): void
    {
        $empresaId = 1;

        DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (
            SELECT id FROM erp_permisos WHERE codigo = 'contabilidad.iva_mapeo.editar'
        )");
        DB::statement("DELETE FROM erp_permisos WHERE codigo = 'contabilidad.iva_mapeo.editar'");

        Schema::dropIfExists('erp_configuracion_iva_mapeo');

        Schema::table('erp_facturas_compra', function (Blueprint $t) {
            foreach ([
                'imp_iva_21', 'imp_iva_10_5', 'imp_iva_27', 'imp_iva_2_5', 'imp_iva_5',
                'imp_percepciones_iva', 'imp_percepciones_iibb', 'imp_percepciones_otros_nac',
                'imp_municipales', 'imp_internos', 'imp_otros_tributos',
            ] as $c) {
                if (Schema::hasColumn('erp_facturas_compra', $c)) {
                    $t->dropColumn($c);
                }
            }
        });

        DB::table('erp_cuentas_contables')
            ->whereIn('codigo', [
                '1.1.6.01.21', '1.1.6.01.10', '1.1.6.01.27', '1.1.6.01.02', '1.1.6.01.05',
                '1.1.6.15', '1.1.6.16', '5.5.08',
            ])
            ->where('empresa_id', $empresaId)
            ->delete();

        // Revertir 1.1.6.01 a imputable=1 (estado previo).
        DB::table('erp_cuentas_contables')
            ->where('codigo', '1.1.6.01')->where('empresa_id', $empresaId)
            ->update(['imputable' => 1, 'updated_at' => now()]);
    }

    private function upsertCuenta(
        int $empresaId, string $codigo, string $nombre, int $padreId, int $nivel, string $tipo,
        ?string $rubro = null, ?string $saldoNormal = null,
    ): void {
        $exists = DB::table('erp_cuentas_contables')
            ->where('codigo', $codigo)->where('empresa_id', $empresaId)
            ->exists();
        if ($exists) return;

        DB::table('erp_cuentas_contables')->insert([
            'empresa_id' => $empresaId,
            'codigo' => $codigo,
            'codigo_padre_id' => $padreId,
            'nivel' => $nivel,
            'nombre' => $nombre,
            'tipo' => $tipo,
            'rubro_ec' => $rubro,
            'imputable' => 1,
            'moneda' => 'ARS',
            'admite_cc' => 0,
            'admite_auxiliar' => 0,
            'saldo_normal' => $saldoNormal,
            'regularizadora' => 0,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
