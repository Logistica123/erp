<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.29 — Restricciones de facturas WS + permisos temporales.
 *
 * Cambios:
 *  1. Permiso `ventas.facturas.eliminar_ws` (sensible=2, super_admin only).
 *  2. Permiso `ventas.facturas.eliminar_sin_cae` (sensible=1, super_admin + contador).
 *  3. Tabla `erp_permisos_temporales` para concesiones con expiración.
 *
 * Concepto nuevo `sensible=2`: ultra-sensible, requiere doble confirmación
 * (escribir "ELIMINAR" + motivo ≥20 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1-2) Permisos nuevos.
        $perms = [
            ['codigo' => 'ventas.facturas.eliminar_ws',
             'modulo' => 'ventas', 'entidad' => 'facturas', 'accion' => 'eliminar_ws',
             'descripcion' => 'Permite eliminar facturas de venta emitidas por Web Service (WSFE). MUY SENSIBLE. Solo super_admin con autorización temporal.',
             'sensible' => 2],
            ['codigo' => 'ventas.facturas.eliminar_sin_cae',
             'modulo' => 'ventas', 'entidad' => 'facturas', 'accion' => 'eliminar_sin_cae',
             'descripcion' => 'Permite eliminar facturas WS que quedaron sin CAE válido (estado ERROR_EMISION o similar).',
             'sensible' => 1],
        ];
        foreach ($perms as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
        }

        // Asignar a roles según matriz del addendum.
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        $contadorId = DB::table('erp_roles')->where('codigo', 'contador')->value('id');
        $permIds = DB::table('erp_permisos')
            ->whereIn('codigo', ['ventas.facturas.eliminar_ws', 'ventas.facturas.eliminar_sin_cae'])
            ->pluck('id', 'codigo')->all();

        if ($superAdminId) {
            foreach ($permIds as $pid) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $superAdminId, 'permiso_id' => $pid], [],
                );
            }
        }
        if ($contadorId && isset($permIds['ventas.facturas.eliminar_sin_cae'])) {
            DB::table('erp_rol_permiso')->updateOrInsert(
                ['rol_id' => $contadorId, 'permiso_id' => $permIds['ventas.facturas.eliminar_sin_cae']], [],
            );
        }

        // 3) Tabla de permisos temporales.
        if (! Schema::hasTable('erp_permisos_temporales')) {
            Schema::create('erp_permisos_temporales', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('permiso_codigo', 80);
                $table->unsignedBigInteger('otorgado_por_user_id');
                $table->text('motivo');
                $table->dateTime('otorgado_at');
                $table->dateTime('expira_at');
                $table->dateTime('usado_at')->nullable()
                    ->comment('Cuándo el user efectivamente usó el permiso (puede ser NULL)');
                $table->dateTime('revocado_at')->nullable();

                $table->index(['user_id', 'permiso_codigo', 'expira_at'], 'idx_user_perm');
                $table->foreign('user_id')->references('id')->on('users');
                $table->foreign('otorgado_por_user_id', 'fk_pt_otorgo')
                    ->references('id')->on('users');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_permisos_temporales');
        DB::table('erp_permisos')
            ->whereIn('codigo', ['ventas.facturas.eliminar_ws', 'ventas.facturas.eliminar_sin_cae'])
            ->delete();
    }
};
