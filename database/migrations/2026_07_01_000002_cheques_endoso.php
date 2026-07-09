<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cheques recibidos — endoso a proveedor.
 *
 * El cheque se entrega a un proveedor como pago (total) imputándolo contra una
 * o más facturas de compra. Se crea una OP local PAGADA (medio CHEQUE_ENDOSADO)
 * con sus op_items — así el saldo de las facturas de compra baja por el circuito
 * normal de la CC de proveedores — y el asiento:
 *   D 2.1.1.01 Proveedores (aux proveedor) / H 1.1.4.04 Valores al Cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE erp_cheques_recibidos MODIFY estado
            ENUM('EN_CARTERA','DEPOSITADO','COBRADO','RECHAZADO','VENCIDO_NO_COBRADO','DESCONTADO','ENDOSADO')
            NOT NULL DEFAULT 'EN_CARTERA'");

        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_cheques_recibidos', 'endoso_op_id')) {
                $t->unsignedBigInteger('endoso_op_id')->nullable()->after('asiento_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (Schema::hasColumn('erp_cheques_recibidos', 'endoso_op_id')) {
                $t->dropColumn('endoso_op_id');
            }
        });
        DB::statement("UPDATE erp_cheques_recibidos SET estado='COBRADO' WHERE estado='ENDOSADO'");
        DB::statement("ALTER TABLE erp_cheques_recibidos MODIFY estado
            ENUM('EN_CARTERA','DEPOSITADO','COBRADO','RECHAZADO','VENCIDO_NO_COBRADO','DESCONTADO')
            NOT NULL DEFAULT 'EN_CARTERA'");
    }
};
