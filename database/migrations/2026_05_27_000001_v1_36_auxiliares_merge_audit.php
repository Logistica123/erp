<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.36 — Audit inmutable de la unificación de auxiliares duplicados por CUIT.
 *
 * NO ejecuta el merge: solo crea la tabla de auditoría. El merge corre vía
 * `php artisan auxiliares:unificar-duplicados --confirm` (con dry-run previo
 * obligatorio + backup). Decisión: solo tipo=Cliente, sin UNIQUE duro en DB
 * (prevención a nivel app en importers + sync). Ver memoria addendum v1.36.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('erp_auxiliares_merge_audit')) return;

        Schema::create('erp_auxiliares_merge_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cuit', 13);
            $table->string('tipo', 30);
            $table->unsignedBigInteger('id_canonico');
            $table->json('ids_mergeados');
            $table->json('codigos_originales');
            $table->json('fks_reasignadas');
            $table->json('snapshot_canonico_pre');
            $table->json('snapshot_canonico_post');
            $table->text('decision_log');
            $table->unsignedBigInteger('ejecutado_por_user_id')->nullable();
            $table->dateTime('ejecutado_at')->useCurrent();
            $table->index('cuit', 'idx_auxmerge_cuit');
            $table->index('id_canonico', 'idx_auxmerge_canon');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_auxiliares_merge_audit');
    }
};
