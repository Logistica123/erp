<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.43 §3 — Crea las 5 cuentas contables del modelo del estudio que faltaban
 * en el plan del ERP. Idempotente: si la cuenta ya existe, no la duplica.
 */
return new class extends Migration
{
    private function cuentas(): array
    {
        return [
            // codigo, padre, nombre, tipo, rubro_ec, admite_cc, admite_auxiliar, tipo_auxiliar, etiqueta_cierre, saldo_normal
            ['1.1.6.17', '1.1.6', 'Percepciones Ganancias Sufridas',           'A', 'Otros Créditos',           0, 0, null,    'PERC-GAN-SUF',     'DEUDOR'],
            ['1.1.6.18', '1.1.6', 'IIBB - Saldo a Favor',                       'A', 'Otros Créditos',           0, 0, null,    'IIBB-SALDO-FAV',   'DEUDOR'],
            ['1.1.5.06', '1.1.5', 'Plan de Autoahorro Mercedes Benz Accelo',    'A', 'Otros Créditos',           1, 0, null,    'PLAN-MB-ACCELO',   'DEUDOR'],
            ['1.2.1.06', '1.2.1', 'Obras en Curso',                             'A', 'Bienes de Uso',            1, 1, 'Bien',  'OBRAS-CURSO',      'DEUDOR'],
            ['2.1.2.09', '2.1.2', 'SCVO a Pagar',                               'P', 'Remuneraciones y Cs. Soc.',1, 0, null,    'SCVO',             'ACREEDOR'],
        ];
    }

    public function up(): void
    {
        $empresaId = DB::table('erp_empresas')->orderBy('id')->value('id');
        if (! $empresaId) return;

        foreach ($this->cuentas() as $c) {
            [$codigo, $padreCodigo, $nombre, $tipo, $rubro, $admiteCc, $admiteAux, $tipoAux, $etiqueta, $saldoNormal] = $c;
            if (DB::table('erp_cuentas_contables')->where('codigo', $codigo)->exists()) continue;
            $padreId = DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)->where('codigo', $padreCodigo)->value('id');
            DB::table('erp_cuentas_contables')->insert([
                'empresa_id' => $empresaId,
                'codigo' => $codigo,
                'codigo_padre_id' => $padreId,
                'nivel' => substr_count($codigo, '.') + 1,
                'nombre' => $nombre,
                'tipo' => $tipo,
                'rubro_ec' => $rubro,
                'imputable' => 1,
                'moneda' => 'ARS',
                'admite_cc' => $admiteCc,
                'admite_auxiliar' => $admiteAux,
                'tipo_auxiliar' => $tipoAux,
                'etiqueta_cierre' => $etiqueta,
                'saldo_normal' => $saldoNormal,
                'regularizadora' => 0,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_column($this->cuentas(), 0) as $codigo) {
            DB::table('erp_cuentas_contables')->where('codigo', $codigo)->delete();
        }
    }
};
