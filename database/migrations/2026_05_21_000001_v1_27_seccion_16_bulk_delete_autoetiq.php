<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.27 §16 — Borrar bulk + auto-etiquetado por reglas regex con cuenta.
 *
 * Cambios:
 *  1. Permiso `tesoreria.movimientos.borrar_bulk` (super_admin).
 *  2. Columna `cuenta_servicios_publicos_id` en `erp_banco_config` (catch-all
 *     para PAGO_SERVICIO sin factura del CUIT detectado).
 *  3. Seed de reglas regex universales en `erp_conciliacion_reglas` para
 *     auto-etiquetar comisiones, impuestos Ley 25413, percepciones IIBB
 *     SIRCREB, IVA Crédito Fiscal y rendimientos.
 *
 * NO se crea estado AUTO_ETIQUETADO porque el enum existente
 * `PENDIENTE/ETIQUETADO/CONCILIADO/IGNORADO` ya cubre el caso: cuando una
 * regla matchea Y tiene `cuenta_contable_id`, el movimiento queda en
 * ETIQUETADO con `cuenta_contable_propuesta_id` seteada (funcionalmente
 * idéntico al AUTO_ETIQUETADO del spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Permiso nuevo
        DB::table('erp_permisos')->updateOrInsert(
            ['codigo' => 'tesoreria.movimientos.borrar_bulk'],
            [
                'modulo' => 'tesoreria',
                'entidad' => 'movimientos',
                'accion' => 'borrar_bulk',
                'descripcion' => 'Permite borrar masivamente movimientos bancarios sin conciliar (operación irreversible).',
                'sensible' => 1,
            ],
        );
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        $permId = DB::table('erp_permisos')
            ->where('codigo', 'tesoreria.movimientos.borrar_bulk')->value('id');
        if ($superAdminId && $permId) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $superAdminId, 'permiso_id' => $permId], [],
            );
        }

        // 2) Columna cuenta_servicios_publicos_id en banco_config (idempotente).
        if (Schema::hasTable('erp_banco_config')
            && ! Schema::hasColumn('erp_banco_config', 'cuenta_servicios_publicos_id')) {
            Schema::table('erp_banco_config', function (Blueprint $table) {
                $table->unsignedBigInteger('cuenta_servicios_publicos_id')->nullable()
                    ->after('cuenta_intereses_ganados_id')
                    ->comment('Catch-all PAGO_SERVICIO sin factura del CUIT detectado');
                $table->foreign('cuenta_servicios_publicos_id', 'fk_bancoconf_servpub')
                    ->references('id')->on('erp_cuentas_contables');
            });
        }

        // 3) Reglas regex universales (banco_id NULL = todos los bancos).
        // Usamos códigos de cuenta para resolver el id real.
        $cuentaId = function (string $codigo) {
            return DB::table('erp_cuentas_contables')
                ->where('empresa_id', 1)
                ->where('codigo', $codigo)
                ->value('id');
        };
        $diarioBan = DB::table('erp_diarios')
            ->where('empresa_id', 1)
            ->where('codigo', 'BAN')
            ->value('id');

        $reglas = [
            // [codigo, descripcion, patron_concepto, signo, cuenta_codigo, prioridad]
            ['V27_IMP_DEB', 'Impuesto Ley 25413 sobre débitos',
                '(?i)IMP\s*S\s*/?\s*DEB(?:\s*CT)?|IMPUESTO.*D[ÉE]BITO',
                'DEBITO', '5.4.04', 10],
            ['V27_IMP_CRED', 'Impuesto Ley 25413 sobre créditos',
                '(?i)IMP\s*S\s*/?\s*CRED(?:\s*CT)?|IMPUESTO.*CR[ÉE]DITO',
                'DEBITO', '5.4.04', 10],
            ['V27_SIRCREB', 'Percepción IIBB SIRCREB',
                '(?i)R\s*/\s*RECAUDACI[ÓO]N\s+IB\s+SIRCREB|SIRCREB',
                'DEBITO', '1.1.6.11', 10],
            ['V27_PERC_IIBB', 'Percepción IIBB / Retención IIBB (genérica)',
                '(?i)PERC(EP)?\.?\s+IIBB|RETENCI[ÓO]N\s+IIBB',
                'DEBITO', '1.1.6.09', 15],
            ['V27_IVA_2408', 'Percepción IVA RG 2408',
                '(?i)IVA\s+RG\s*2408|PERCEPCI[ÓO]N\s+IVA\s+RG\s*2408',
                'DEBITO', '1.1.6.04', 10],
            ['V27_COMIS_BAN', 'Comisión bancaria genérica',
                '(?i)COM(ISI[ÓO]N)?\s+(B@C|BANCARIA|MENSUAL|MANTENIMIENTO|POR\s+USO|TRANSFER|CUSTODIA)',
                'DEBITO', '5.4.02', 15],
            ['V27_MANTENIMIENTO', 'Mantenimiento de cuenta',
                '(?i)MANTENIMIENTO\s+(DE\s+)?CUENTA|CARGO\s+POR\s+SERVICIO',
                'DEBITO', '5.4.02', 15],
            ['V27_IVA_COM', 'IVA sobre comisión',
                '(?i)^\s*I\s*V\s*A\s*$|^IVA(\s+S\s*/.*COMISI[ÓO]N)?',
                'DEBITO', '1.1.6.01', 20],
            ['V27_INT_GANADO', 'Rendimiento / Interés a favor',
                '(?i)RENDIMIENTO|INTER[EÉ]S\s+(A\s+)?FAVOR|ACREDITACI[ÓO]N\s+INTER[EÉ]S|INT(ERES)?\s+CUENTA\s+REMUNER',
                'CREDITO', '4.2.01', 15],
            ['V27_RET_GAN', 'Retención Ganancias RG 830',
                '(?i)RET(ENCI[ÓO]N)?\s+GANANCIAS|RG\s*830',
                'DEBITO', '1.1.6.06', 15],
        ];

        foreach ($reglas as [$cod, $desc, $patron, $signo, $cuentaCod, $prio]) {
            $cuentaId_ = $cuentaId($cuentaCod);
            if (! $cuentaId_) {
                // Si la cuenta del plan no existe, no insertamos esa regla (evita FK error).
                continue;
            }
            DB::table('erp_conciliacion_reglas')->updateOrInsert(
                ['empresa_id' => 1, 'codigo' => $cod],
                [
                    'descripcion' => $desc,
                    'tipo' => 'CONCEPTO_REGEX',
                    'patron_concepto' => $patron,
                    'cuenta_contable_id' => $cuentaId_,
                    'diario_id' => $diarioBan,
                    'orden_prioridad' => $prio,
                    'activa' => 1,
                    'banco_id' => null,
                    'signo' => $signo,
                    'confianza' => 90,
                    'observacion' => '§16 universal',
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('erp_conciliacion_reglas')->where('codigo', 'like', 'V27_%')->delete();
        DB::table('erp_permisos')->where('codigo', 'tesoreria.movimientos.borrar_bulk')->delete();
        if (Schema::hasTable('erp_banco_config')
            && Schema::hasColumn('erp_banco_config', 'cuenta_servicios_publicos_id')) {
            Schema::table('erp_banco_config', function (Blueprint $table) {
                $table->dropForeign('fk_bancoconf_servpub');
                $table->dropColumn('cuenta_servicios_publicos_id');
            });
        }
    }
};
