<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.42 Fase C — Inversiones (FCI + Plazos Fijos + Cauciones + Bonos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_inversiones')) {
            Schema::create('erp_inversiones', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('nombre', 100);
                $t->enum('tipo', ['FCI', 'PLAZO_FIJO', 'CAUCION', 'BONO', 'OTRO']);
                $t->string('entidad', 80);
                $t->string('moneda', 3)->default('ARS');
                $t->unsignedBigInteger('cuenta_contable_id')->nullable();
                $t->boolean('activo')->default(true);
                $t->date('fecha_alta');
                $t->date('fecha_baja')->nullable();
                $t->integer('plazo_dias')->nullable();
                $t->decimal('tasa_nominal', 8, 4)->nullable();
                $t->date('fecha_vencimiento')->nullable();
                $t->decimal('saldo_actual', 18, 2)->default(0);
                $t->decimal('ganancia_acumulada', 18, 2)->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['empresa_id', 'nombre'], 'uk_inv_empresa_nombre');
                $t->index('tipo', 'idx_inv_tipo');
                $t->index('activo', 'idx_inv_activo');
            });
        }

        if (! Schema::hasTable('erp_inversiones_movimientos')) {
            Schema::create('erp_inversiones_movimientos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('inversion_id');
                $t->date('fecha');
                $t->enum('tipo', [
                    'SUSCRIPCION', 'RESCATE', 'INTERES', 'CONSTITUCION',
                    'VENCIMIENTO', 'AJUSTE_SALDO_FONDO',
                ]);
                $t->decimal('importe', 18, 2);
                $t->decimal('saldo_segun_rys', 18, 2);
                $t->decimal('saldo_segun_fondo', 18, 2)->nullable();
                $t->unsignedBigInteger('cuenta_bancaria_id')->nullable();
                $t->unsignedBigInteger('asiento_id')->nullable();
                $t->text('observaciones')->nullable();
                $t->unsignedBigInteger('registrado_por_user_id');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['inversion_id', 'fecha'], 'idx_inv_mov_fecha');
                $t->index('tipo', 'idx_inv_mov_tipo');
            });
        }

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inversiones_movimientos');
        Schema::dropIfExists('erp_inversiones');
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'inversiones.ver', 'modulo' => 'tesoreria', 'entidad' => 'inversiones', 'accion' => 'ver',
             'descripcion' => 'Ver inversiones (FCI + Plazos Fijos + Cauciones + Bonos).', 'sensible' => 0],
            ['codigo' => 'inversiones.crear', 'modulo' => 'tesoreria', 'entidad' => 'inversiones', 'accion' => 'crear',
             'descripcion' => 'Crear nueva inversión.', 'sensible' => 1],
            ['codigo' => 'inversiones.registrar_movimiento', 'modulo' => 'tesoreria', 'entidad' => 'inversiones_movimientos', 'accion' => 'registrar',
             'descripcion' => 'Registrar movimiento (suscripción / rescate / interés / vencimiento).', 'sensible' => 1],
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
