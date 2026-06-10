<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cheques físicos recibidos en cobros (recibos con medio "Cheques en Cartera").
 *
 * Lifecycle del cheque:
 *   EN_CARTERA → DEPOSITADO → COBRADO   (camino feliz, queda acreditado en banco)
 *   EN_CARTERA → RECHAZADO              (cheque sin fondos o rebotado)
 *   EN_CARTERA → VENCIDO_NO_COBRADO     (cron diario marca cheques con fecha_pago < hoy aún EN_CARTERA)
 *
 * El cheque se crea automáticamente cuando se emite un recibo con
 * medio_cobro_id apuntando a la cuenta bancaria `CHEQUES_CARTERA`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_cheques_recibidos')) {
            Schema::create('erp_cheques_recibidos', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->unsignedBigInteger('recibo_id');

                // Datos del cheque (papel).
                $t->string('numero_cheque', 30);
                $t->string('banco_emisor', 100);
                $t->string('cuit_librador', 13)->nullable();
                $t->string('librador_nombre', 200)->nullable();
                $t->date('fecha_emision');
                $t->date('fecha_pago');  // Vencimiento (cuándo se puede cobrar)
                $t->decimal('importe', 18, 2);

                // Lifecycle
                $t->enum('estado', ['EN_CARTERA', 'DEPOSITADO', 'COBRADO', 'RECHAZADO', 'VENCIDO_NO_COBRADO'])
                  ->default('EN_CARTERA');

                // Cuando se deposita / acredita.
                $t->unsignedBigInteger('cuenta_bancaria_deposito_id')->nullable();
                $t->date('fecha_deposito')->nullable();
                $t->date('fecha_acreditacion')->nullable();
                $t->unsignedBigInteger('mov_bancario_id')->nullable();

                // Rechazo
                $t->date('fecha_rechazo')->nullable();
                $t->text('motivo_rechazo')->nullable();

                $t->text('observaciones')->nullable();
                $t->unsignedBigInteger('created_by_user_id');
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $t->index('recibo_id', 'idx_cheq_recibo');
                $t->index('estado', 'idx_cheq_estado');
                $t->index(['fecha_pago', 'estado'], 'idx_cheq_vto_estado');
                $t->index('numero_cheque', 'idx_cheq_numero');
            });
        }

        $this->seedPermisos();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_cheques_recibidos');
    }

    private function seedPermisos(): void
    {
        $permisos = [
            ['codigo' => 'tesoreria.cheques.ver',       'modulo' => 'tesoreria', 'entidad' => 'cheques_recibidos', 'accion' => 'ver',       'descripcion' => 'Ver listado de cheques recibidos.', 'sensible' => 0],
            ['codigo' => 'tesoreria.cheques.gestionar', 'modulo' => 'tesoreria', 'entidad' => 'cheques_recibidos', 'accion' => 'gestionar', 'descripcion' => 'Depositar / cobrar / rechazar cheques recibidos.', 'sensible' => 1],
        ];
        $superAdminId = DB::table('erp_roles')->where('codigo', 'super_admin')->value('id');
        foreach ($permisos as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
            $pid = DB::table('erp_permisos')->where('codigo', $p['codigo'])->value('id');
            if ($superAdminId && $pid) {
                DB::table('erp_rol_permiso')->updateOrInsert(['rol_id' => $superAdminId, 'permiso_id' => $pid], []);
            }
        }
    }
};
