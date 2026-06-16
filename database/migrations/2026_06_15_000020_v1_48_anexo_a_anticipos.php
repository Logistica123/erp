<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.48 Anexo A (Caso Ruefli — N:1 con anticipos).
 * Traza qué movimiento posterior canceló un movimiento de adelanto cuando se
 * imputa contra factura descontando anticipos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('erp_movimientos_bancarios', 'anticipo_cancelado_por_mov_id')) {
            Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
                $t->unsignedBigInteger('anticipo_cancelado_por_mov_id')->nullable()
                    ->comment('Si este mov es un adelanto, indica qué mov posterior lo canceló al imputarse contra factura');
                $t->index('anticipo_cancelado_por_mov_id', 'idx_mov_anticipo_cancela');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('erp_movimientos_bancarios', 'anticipo_cancelado_por_mov_id')) {
            Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
                $t->dropIndex('idx_mov_anticipo_cancela');
                $t->dropColumn('anticipo_cancelado_por_mov_id');
            });
        }
    }
};
