<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.27 §17 — Alta de cuenta ICBC USD en erp_cuentas_bancarias + config.
 *
 * Datos provistos por Matías:
 *   Número: 0535/11111941/41
 *   CBU: 0150535111000111941414
 *   Tipo: Cuenta Corriente Especial Jurídica USD
 *   Apertura: 17/03/2026
 *   Alias CBU: NULL (no aparece todavía)
 *   Titular: Logística Argentina SRL (mismo que ICBC ARS)
 *
 * Mapeo a IDs reales:
 *   banco_id = 1 (ICBC)
 *   moneda_id = 2 (USD)
 *   cuenta_contable_id = 58 (1.1.2.05 ICBC Cta Cte Dólares)
 *
 * Banco_config con cuentas contables genéricas (las mismas que ARS por ahora):
 *   gastos_bancarios = 228 (5.4.02)
 *   imp_debito_credito = 230 (5.4.04)
 *   intereses_ganados = 163 (4.2.01)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resolver IDs por código (varían entre dev/prod).
        $bancoIcbcId = DB::table('erp_bancos')->where('codigo', 'ICBC')->value('id');
        $monedaUsdId = DB::table('erp_monedas')->where('codigo', 'USD')->value('id');
        $ctaIcbcUsd = DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)
            ->where('codigo', '1.1.2.05')
            ->value('id');
        $ctaGastosBancarios = DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('codigo', '5.4.02')->value('id');
        $ctaImpDebCred = DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('codigo', '5.4.04')->value('id');
        $ctaInteresesGanados = DB::table('erp_cuentas_contables')
            ->where('empresa_id', 1)->where('codigo', '4.2.01')->value('id');

        if (! $bancoIcbcId || ! $monedaUsdId || ! $ctaIcbcUsd) {
            // Si falta algo (entorno dev sin seeds completos), no rompe migración.
            return;
        }

        // Idempotente: si ya existe la cuenta ICBC USD, no se duplica.
        $existe = DB::table('erp_cuentas_bancarias')
            ->where('empresa_id', 1)
            ->where('banco_id', $bancoIcbcId)
            ->where('moneda_id', $monedaUsdId)
            ->first(['id']);

        if (! $existe) {
            $cuentaId = DB::table('erp_cuentas_bancarias')->insertGetId([
                'empresa_id' => 1,
                'banco_id' => $bancoIcbcId,
                'cuenta_contable_id' => $ctaIcbcUsd,
                'moneda_id' => $monedaUsdId,
                'codigo' => 'ICBC_CC_USD',
                'nombre' => 'ICBC Cuenta Corriente USD',
                'tipo' => 'CC',
                'numero_cuenta' => '0535/11111941/41',
                'cbu' => '0150535111000111941414',
                'alias_cbu' => null,
                'saldo_actual' => 0,
                'saldo_moneda_origen' => 0,
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $cuentaId = $existe->id;
        }

        // Banco config (idempotente).
        $cfgExiste = DB::table('erp_banco_config')
            ->where('cuenta_bancaria_id', $cuentaId)->exists();
        if (! $cfgExiste && $ctaGastosBancarios && $ctaImpDebCred && $ctaInteresesGanados) {
            DB::table('erp_banco_config')->insert([
                'cuenta_bancaria_id' => $cuentaId,
                'cuenta_gastos_bancarios_id' => $ctaGastosBancarios,
                'cuenta_imp_debito_credito_id' => $ctaImpDebCred,
                'cuenta_intereses_ganados_id' => $ctaInteresesGanados,
                'cuenta_servicios_publicos_id' => null,
                'observaciones' => 'Auto-creada por v1.27 §17. Cuenta USD usa cuentas contables ARS por defecto — verificar con contador.',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Borrar config primero (FK), después la cuenta.
        $cuentaId = DB::table('erp_cuentas_bancarias')
            ->where('empresa_id', 1)
            ->where('banco_id', 1)
            ->where('moneda_id', 2)
            ->where('codigo', 'ICBC_CC_USD')
            ->value('id');
        if ($cuentaId) {
            DB::table('erp_banco_config')->where('cuenta_bancaria_id', $cuentaId)->delete();
            DB::table('erp_cuentas_bancarias')->where('id', $cuentaId)->delete();
        }
    }
};
