<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.27 Sprint A — Tipo operativo del movimiento bancario + config por banco.
 *
 * Cambios:
 *  1. `erp_movimientos_bancarios.tipo_operativo`: enum con 9 valores que
 *     clasifican operativamente cada movimiento (sirve para sugerir matches
 *     al conciliar y para reportes). Se infiere por regex sobre el concepto
 *     usando las reglas de `erp_conciliacion_reglas` (que ya tenemos).
 *  2. `erp_movimientos_bancarios.monto_conciliado`: para soportar
 *     conciliación parcial (movimiento por $300k contra factura por $500k).
 *  3. `erp_banco_config`: 1 fila por cuenta bancaria con las 3 cuentas
 *     contables a usar para los tipos auto (COMISION/IMPUESTO/INTERES).
 *  4. 4 permisos `tesoreria.extractos.*`.
 *
 * NO se crea una tabla `erp_movimientos_extracto` nueva: usamos la existente
 * `erp_movimientos_bancarios` que ya tiene la estructura completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: alguna iteración anterior (no rastreada) puede haber
        // creado ya estas columnas/tabla. Chequeamos antes de crearlas.
        Schema::table('erp_movimientos_bancarios', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'tipo_operativo')) {
                $table->enum('tipo_operativo', [
                    'TRANSFERENCIA_RECIBIDA',
                    'TRANSFERENCIA_ENVIADA',
                    'PAGO_SERVICIO',
                    'COMISION_BANCARIA',
                    'IMPUESTO_DEBITO_CREDITO',
                    'DEPOSITO',
                    'EXTRACCION',
                    'INTERES_GANADO',
                    'OTRO',
                ])->default('OTRO')->after('concepto')->index();
            }
            if (! Schema::hasColumn('erp_movimientos_bancarios', 'monto_conciliado')) {
                $table->decimal('monto_conciliado', 18, 2)->default(0)
                    ->after('estado')
                    ->comment('Para conciliaciones parciales (mov $300k contra factura $500k)');
            }
        });

        if (Schema::hasTable('erp_banco_config')) {
            // Ya existe — solo procesamos permisos.
            $this->aplicarPermisos();
            return;
        }

        Schema::create('erp_banco_config', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cuenta_bancaria_id')->unique();
            $table->unsignedBigInteger('cuenta_gastos_bancarios_id');
            $table->unsignedBigInteger('cuenta_imp_debito_credito_id');
            $table->unsignedBigInteger('cuenta_intereses_ganados_id');
            $table->text('observaciones')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('cuenta_bancaria_id', 'fk_bancoconf_cuenta')
                ->references('id')->on('erp_cuentas_bancarias');
            $table->foreign('cuenta_gastos_bancarios_id', 'fk_bancoconf_gtos')
                ->references('id')->on('erp_cuentas_contables');
            $table->foreign('cuenta_imp_debito_credito_id', 'fk_bancoconf_imp')
                ->references('id')->on('erp_cuentas_contables');
            $table->foreign('cuenta_intereses_ganados_id', 'fk_bancoconf_int')
                ->references('id')->on('erp_cuentas_contables');
        });

        $this->aplicarPermisos();
    }

    private function aplicarPermisos(): void
    {
        foreach ([
            ['tesoreria', 'extractos', 'cargar', 'Permite subir extractos bancarios en el wizard.'],
            ['tesoreria', 'extractos', 'borrar', 'Permite borrar extractos cargados (libera hash para re-importar).'],
            ['tesoreria', 'extractos', 'conciliar', 'Permite conciliar movimientos del extracto contra facturas o asientos directos.'],
            ['tesoreria', 'extracto_config', 'editar', 'Permite editar la configuración de cuentas contables por banco + reglas regex.'],
        ] as [$modulo, $entidad, $accion, $desc]) {
            $codigo = "{$modulo}.{$entidad}.{$accion}";
            DB::table('erp_permisos')->updateOrInsert(
                ['codigo' => $codigo],
                [
                    'modulo' => $modulo,
                    'entidad' => $entidad,
                    'accion' => $accion,
                    'descripcion' => $desc,
                    'sensible' => 1,
                ],
            );
        }

        // Asignar a super_admin y tesorero los permisos que apliquen.
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        $tesoreroId = DB::table('erp_roles')->where('codigo', 'tesorero')->value('id');
        $contadorId = DB::table('erp_roles')->where('codigo', 'contador')->value('id');

        $perms = DB::table('erp_permisos')
            ->whereIn('codigo', [
                'tesoreria.extractos.cargar',
                'tesoreria.extractos.borrar',
                'tesoreria.extractos.conciliar',
                'tesoreria.extracto_config.editar',
            ])->pluck('id', 'codigo')->all();

        $links = [];
        if ($superAdminId) {
            foreach ($perms as $pid) $links[] = ['rol_id' => $superAdminId, 'permiso_id' => $pid];
        }
        if ($tesoreroId) {
            foreach (['tesoreria.extractos.cargar', 'tesoreria.extractos.conciliar'] as $c) {
                if (isset($perms[$c])) $links[] = ['rol_id' => $tesoreroId, 'permiso_id' => $perms[$c]];
            }
        }
        if ($contadorId) {
            foreach (['tesoreria.extractos.cargar', 'tesoreria.extractos.conciliar', 'tesoreria.extracto_config.editar'] as $c) {
                if (isset($perms[$c])) $links[] = ['rol_id' => $contadorId, 'permiso_id' => $perms[$c]];
            }
        }
        foreach ($links as $l) {
            DB::table('erp_rol_permiso')->updateOrInsert($l, []);
        }
    }

    public function down(): void
    {
        DB::table('erp_permisos')->whereIn('codigo', [
            'tesoreria.extractos.cargar',
            'tesoreria.extractos.borrar',
            'tesoreria.extractos.conciliar',
            'tesoreria.extracto_config.editar',
        ])->delete();

        Schema::dropIfExists('erp_banco_config');

        Schema::table('erp_movimientos_bancarios', function (Blueprint $table) {
            $table->dropColumn(['tipo_operativo', 'monto_conciliado']);
        });
    }
};
