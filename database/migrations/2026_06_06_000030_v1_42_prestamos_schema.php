<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.42 Fase D — Préstamos otorgados/recibidos con cronograma (Francés/Alemán/Americano/Bullet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_prestamos')) {
            Schema::create('erp_prestamos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->enum('tipo', ['OTORGADO', 'RECIBIDO']);
                $t->unsignedBigInteger('contraparte_auxiliar_id');
                $t->string('nombre', 150);
                $t->decimal('capital', 18, 2);
                $t->string('moneda', 3)->default('ARS');
                $t->decimal('tasa_mensual', 8, 4)->nullable();
                $t->decimal('tasa_nominal_anual', 8, 4)->nullable();
                $t->enum('sistema_amortizacion', ['FRANCES', 'ALEMAN', 'AMERICANO', 'BULLET'])->default('FRANCES');
                $t->integer('plazo_cuotas');
                $t->date('fecha_otorgamiento');
                $t->date('fecha_primera_cuota');
                $t->enum('estado', ['VIGENTE', 'CANCELADO', 'INCOBRABLE'])->default('VIGENTE');
                $t->unsignedBigInteger('cuenta_contable_id')->nullable();
                $t->text('observaciones')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['tipo', 'estado'], 'idx_pre_tipo_estado');
                $t->index('contraparte_auxiliar_id', 'idx_pre_contraparte');
            });
        }

        if (! Schema::hasTable('erp_prestamos_cuotas')) {
            Schema::create('erp_prestamos_cuotas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('prestamo_id');
                $t->integer('numero_cuota');
                $t->date('fecha_vencimiento');
                $t->decimal('capital', 18, 2);
                $t->decimal('interes', 18, 2);
                $t->decimal('total_cuota', 18, 2);
                $t->decimal('capital_adeudado_post', 18, 2);
                $t->enum('estado', ['PENDIENTE', 'PAGADA', 'VENCIDA'])->default('PENDIENTE');
                $t->date('fecha_pago')->nullable();
                $t->decimal('importe_pagado', 18, 2)->nullable();
                $t->unsignedBigInteger('op_pago_id')->nullable();
                $t->unsignedBigInteger('recibo_cobro_id')->nullable();
                $t->unsignedBigInteger('asiento_id')->nullable();
                $t->text('observaciones')->nullable();
                $t->unique(['prestamo_id', 'numero_cuota'], 'uk_pre_cuota');
                $t->index(['fecha_vencimiento', 'estado'], 'idx_pre_cuota_fecha_estado');
            });
        }

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_prestamos_cuotas');
        Schema::dropIfExists('erp_prestamos');
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'prestamos.ver', 'modulo' => 'tesoreria', 'entidad' => 'prestamos', 'accion' => 'ver',
             'descripcion' => 'Ver préstamos otorgados y recibidos.', 'sensible' => 0],
            ['codigo' => 'prestamos.crear', 'modulo' => 'tesoreria', 'entidad' => 'prestamos', 'accion' => 'crear',
             'descripcion' => 'Crear préstamo con cronograma auto-generado.', 'sensible' => 1],
            ['codigo' => 'prestamos.registrar_pago_cuota', 'modulo' => 'tesoreria', 'entidad' => 'prestamos_cuotas', 'accion' => 'pagar',
             'descripcion' => 'Registrar pago/cobro de cuota.', 'sensible' => 1],
            ['codigo' => 'prestamos.cancelar', 'modulo' => 'tesoreria', 'entidad' => 'prestamos', 'accion' => 'cancelar',
             'descripcion' => 'Cancelar préstamo total anticipado o marcar INCOBRABLE.', 'sensible' => 1],
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
