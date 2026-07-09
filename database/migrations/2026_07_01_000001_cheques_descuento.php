<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cheques recibidos — descuento (venta del cheque con quita).
 *
 * Un cheque de $100 se "vende" y se cobran ~$80: la diferencia son intereses,
 * IVA y comisión. Nuevo estado DESCONTADO + columnas con el desglose + FK al
 * asiento generado:
 *   D banco (neto) + D intereses (5.4.01) + D comisión (5.4.02)
 *   + D IVA CF 21% (1.1.6.01.21)  /  H 1.1.4.04 Valores al Cobro (importe).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE erp_cheques_recibidos MODIFY estado
            ENUM('EN_CARTERA','DEPOSITADO','COBRADO','RECHAZADO','VENCIDO_NO_COBRADO','DESCONTADO')
            NOT NULL DEFAULT 'EN_CARTERA'");

        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_cheques_recibidos', 'descuento_intereses')) {
                $t->decimal('descuento_intereses', 18, 2)->nullable()->after('fecha_acreditacion');
                $t->decimal('descuento_iva', 18, 2)->nullable()->after('descuento_intereses');
                $t->decimal('descuento_comision', 18, 2)->nullable()->after('descuento_iva');
                $t->decimal('descuento_neto', 18, 2)->nullable()->after('descuento_comision');
                $t->unsignedBigInteger('asiento_id')->nullable()->after('descuento_neto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_cheques_recibidos', function (Blueprint $t) {
            foreach (['descuento_intereses', 'descuento_iva', 'descuento_comision', 'descuento_neto', 'asiento_id'] as $c) {
                if (Schema::hasColumn('erp_cheques_recibidos', $c)) $t->dropColumn($c);
            }
        });
        DB::statement("UPDATE erp_cheques_recibidos SET estado='COBRADO' WHERE estado='DESCONTADO'");
        DB::statement("ALTER TABLE erp_cheques_recibidos MODIFY estado
            ENUM('EN_CARTERA','DEPOSITADO','COBRADO','RECHAZADO','VENCIDO_NO_COBRADO')
            NOT NULL DEFAULT 'EN_CARTERA'");
    }
};
