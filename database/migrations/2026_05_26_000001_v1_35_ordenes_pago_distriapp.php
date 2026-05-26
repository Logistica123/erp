<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.35 — Órdenes de Pago: sync DistriApp + OP locales.
 *
 * Decisión (con Matías 2026-05-26): EXTENDER la tabla `erp_ordenes_pago`
 * existente (SPEC 02, 0 filas, workflow de banco) en vez de recrearla. Se
 * conserva el workflow de banco (CARGADA_BANCO/LIBERADA) y se suma encima la
 * capa v1.35: origen LOCAL/DISTRIAPP, sync, tipos catálogo, contabilización
 * manual, audit.
 *
 * Cambios:
 *  1. ALTER erp_ordenes_pago: +origen, +distriapp_*, +tipo_op_id, +snapshot,
 *     +contabilizada/asiento, +sync_*, +cobro v1.35 (moneda USD, medio_pago string).
 *     + estado EMITIDA al enum.
 *  2. erp_ordenes_pago_tipos (catálogo) + seed.
 *  3. erp_ordenes_pago_audit (insert-only).
 *  4. erp_secuencias_op (OP-YYYY-NNNNNN, reinicio anual).
 *  5. erp_mapeo_bancos_distriapp (banco_origen string → cuenta_bancaria ERP).
 *  6. 8 permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tipo real de erp_cuentas_bancarias.id (int en prod, bigint en local).
        $tipoCb = $this->detectarTipoColumna('erp_cuentas_bancarias', 'id');

        // 1) Catálogo de tipos (antes del ALTER por la FK).
        if (! Schema::hasTable('erp_ordenes_pago_tipos')) {
            Schema::create('erp_ordenes_pago_tipos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->string('codigo', 20);
                $table->string('nombre', 100);
                $table->unsignedBigInteger('cuenta_contable_default_id')->nullable();
                $table->boolean('activo')->default(true);
                $table->integer('orden')->default(0);
                $table->dateTime('created_at')->useCurrent();
                $table->unique(['empresa_id', 'codigo'], 'uk_optipo_codigo');
                $table->foreign('empresa_id', 'fk_optipo_empresa')->references('id')->on('erp_empresas');
                $table->foreign('cuenta_contable_default_id', 'fk_optipo_cuenta')
                    ->references('id')->on('erp_cuentas_contables');
            });

            $tipos = [
                ['PROV', 'Pago a Proveedor', 10],
                ['DIST', 'Pago a Distribuidor (DistriApp)', 20],
                ['SUEL_ADM', 'Sueldos Administrativos', 30],
                ['SUEL_OPE', 'Sueldos Operativos', 40],
                ['SERV_PUB', 'Servicios Públicos', 50],
                ['ALQ', 'Alquiler', 60],
                ['HON', 'Honorarios Profesionales', 70],
                ['IMP', 'Impuestos', 80],
                ['RET_SOC', 'Retiro de Socios', 90],
                ['OTRO', 'Otros', 100],
            ];
            foreach ($tipos as [$cod, $nom, $ord]) {
                DB::table('erp_ordenes_pago_tipos')->insert([
                    'empresa_id' => 1, 'codigo' => $cod, 'nombre' => $nom,
                    'orden' => $ord, 'activo' => 1, 'created_at' => now(),
                ]);
            }
        }

        // 2) ALTER erp_ordenes_pago — sumar columnas v1.35.
        Schema::table('erp_ordenes_pago', function (Blueprint $table) use ($tipoCb) {
            if (! Schema::hasColumn('erp_ordenes_pago', 'origen')) {
                $table->enum('origen', ['LOCAL', 'DISTRIAPP'])->default('LOCAL')->after('empresa_id');
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'distriapp_op_id')) {
                $table->unsignedBigInteger('distriapp_op_id')->nullable()->after('origen');
                $table->unsignedBigInteger('distriapp_concepto_id')->nullable()->after('distriapp_op_id');
                $table->string('distriapp_numero_correlativo', 30)->nullable()->after('distriapp_concepto_id');
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'tipo_op_id')) {
                $table->unsignedBigInteger('tipo_op_id')->nullable()->after('tipo')
                    ->comment('v1.35 — FK catálogo erp_ordenes_pago_tipos (coexiste con enum tipo)');
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'beneficiario_snapshot')) {
                $table->json('beneficiario_snapshot')->nullable();
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'cotizacion_usd')) {
                $table->decimal('cotizacion_usd', 10, 4)->nullable();
                $table->decimal('importe_ars_equivalente', 18, 2)->nullable();
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'medio_pago')) {
                $table->string('medio_pago', 50)->nullable()
                    ->comment('v1.35 — medio simple para OP local (existe erp_op_medios para multi-medio)');
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'cuenta_bancaria_pago_id')) {
                if ($tipoCb === 'int') {
                    $table->unsignedInteger('cuenta_bancaria_pago_id')->nullable();
                } else {
                    $table->unsignedBigInteger('cuenta_bancaria_pago_id')->nullable();
                }
                $table->string('referencia_pago', 100)->nullable();
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'contabilizada')) {
                $table->boolean('contabilizada')->default(false);
                $table->dateTime('fecha_contabilizada')->nullable();
                $table->unsignedBigInteger('contabilizada_por_user_id')->nullable();
            }
            if (! Schema::hasColumn('erp_ordenes_pago', 'sync_ultima_actualizacion')) {
                $table->dateTime('sync_ultima_actualizacion')->nullable();
                $table->string('sync_hash', 64)->nullable();
                $table->json('sync_payload_completo')->nullable();
            }
        });

        // Indices + FKs (idempotentes).
        $this->crearIndiceSiFalta('erp_ordenes_pago', 'uk_op_distriapp_id', 'UNIQUE', 'distriapp_op_id');
        $this->crearIndiceSiFalta('erp_ordenes_pago', 'ix_op_origen_estado', 'INDEX', 'origen, estado');
        $this->crearIndiceSiFalta('erp_ordenes_pago', 'ix_op_contabilizada', 'INDEX', 'contabilizada, fecha');
        $this->crearFkSiFalta('erp_ordenes_pago', 'fk_op_tipo_op', 'tipo_op_id', 'erp_ordenes_pago_tipos', 'id');
        $this->crearFkSiFalta('erp_ordenes_pago', 'fk_op_cta_banco_pago', 'cuenta_bancaria_pago_id', 'erp_cuentas_bancarias', 'id');

        // Agregar EMITIDA al enum estado (preserva los valores existentes).
        DB::statement("ALTER TABLE erp_ordenes_pago MODIFY COLUMN estado
            ENUM('BORRADOR','EMITIDA','CARGADA_BANCO','LIBERADA','PAGADA','RECHAZADA','ANULADA')
            NOT NULL DEFAULT 'BORRADOR'");

        // 3) Audit.
        if (! Schema::hasTable('erp_ordenes_pago_audit')) {
            Schema::create('erp_ordenes_pago_audit', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('op_id');
                $table->enum('accion', ['CREAR', 'EDITAR', 'EMITIR', 'PAGAR', 'CONTABILIZAR', 'ANULAR', 'SYNC_UPDATE', 'SYNC_UPDATE_BLOQUEADO']);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('snapshot_antes')->nullable();
                $table->json('snapshot_despues');
                $table->text('motivo')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->index(['op_id', 'created_at'], 'idx_opaudit_op');
                $table->foreign('op_id', 'fk_opaudit_op')->references('id')->on('erp_ordenes_pago')->cascadeOnDelete();
            });
        }

        // 4) Secuencia OP-YYYY-NNNNNN.
        if (! Schema::hasTable('erp_secuencias_op')) {
            Schema::create('erp_secuencias_op', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->smallInteger('anio');
                $table->integer('ultimo_numero')->default(0);
                $table->unique(['empresa_id', 'anio'], 'uk_opseq_empresa_anio');
            });
        }

        // 5) Mapeo banco DistriApp (string) → cuenta bancaria ERP.
        if (! Schema::hasTable('erp_mapeo_bancos_distriapp')) {
            Schema::create('erp_mapeo_bancos_distriapp', function (Blueprint $table) use ($tipoCb) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->string('banco_origen_distriapp', 50)->comment('Ej: ICBC, BRUBANK, MERCADOPAGO');
                if ($tipoCb === 'int') {
                    $table->unsignedInteger('cuenta_bancaria_id');
                } else {
                    $table->unsignedBigInteger('cuenta_bancaria_id');
                }
                $table->dateTime('created_at')->useCurrent();
                $table->unique(['empresa_id', 'banco_origen_distriapp'], 'uk_mapbanco');
                $table->foreign('cuenta_bancaria_id', 'fk_mapbanco_cta')->references('id')->on('erp_cuentas_bancarias');
            });
        }

        // 6) Permisos.
        $perms = [
            ['tesoreria.op.ver', 'op', 'ver', 'Ver órdenes de pago', 0],
            ['tesoreria.op.crear_local', 'op', 'crear_local', 'Crear órdenes de pago locales', 0],
            ['tesoreria.op.editar', 'op', 'editar', 'Editar órdenes de pago locales no contabilizadas', 0],
            ['tesoreria.op.pagar', 'op', 'pagar', 'Registrar pago de una OP', 0],
            ['tesoreria.op.contabilizar', 'op', 'contabilizar', 'Generar asiento contable de una OP', 0],
            ['tesoreria.op.anular', 'op', 'anular', 'Anular órdenes de pago', 1],
            ['tesoreria.op.sync_forzar', 'op', 'sync_forzar', 'Forzar sync de OP desde DistriApp', 0],
            ['tesoreria.op.tipos_administrar', 'op', 'tipos_administrar', 'Administrar catálogo de tipos de OP', 0],
        ];
        foreach ($perms as [$cod, $ent, $acc, $desc, $sens]) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $cod], [
                'codigo' => $cod, 'modulo' => 'tesoreria', 'entidad' => $ent,
                'accion' => $acc, 'descripcion' => $desc, 'sensible' => $sens,
            ]);
        }
        $matriz = [
            'super_admin' => ['tesoreria.op.ver', 'tesoreria.op.crear_local', 'tesoreria.op.editar', 'tesoreria.op.pagar', 'tesoreria.op.contabilizar', 'tesoreria.op.anular', 'tesoreria.op.sync_forzar', 'tesoreria.op.tipos_administrar'],
            'contador' => ['tesoreria.op.ver', 'tesoreria.op.crear_local', 'tesoreria.op.editar', 'tesoreria.op.pagar', 'tesoreria.op.contabilizar', 'tesoreria.op.tipos_administrar'],
            'tesorero' => ['tesoreria.op.ver', 'tesoreria.op.crear_local', 'tesoreria.op.editar', 'tesoreria.op.pagar'],
            'admin' => ['tesoreria.op.ver', 'tesoreria.op.crear_local', 'tesoreria.op.editar', 'tesoreria.op.pagar', 'tesoreria.op.contabilizar', 'tesoreria.op.anular', 'tesoreria.op.sync_forzar', 'tesoreria.op.tipos_administrar'],
            'auditor' => ['tesoreria.op.ver'],
            'revisor_fiscal' => ['tesoreria.op.ver'],
        ];
        $rolesIds = DB::table('erp_roles')->pluck('id', 'codigo');
        $permIds = DB::table('erp_permisos')->where('modulo', 'tesoreria')->where('entidad', 'op')->pluck('id', 'codigo');
        foreach ($matriz as $rolCod => $codigos) {
            $rolId = $rolesIds[$rolCod] ?? null;
            if (! $rolId) continue;
            foreach ($codigos as $cod) {
                if (isset($permIds[$cod])) {
                    DB::table('erp_rol_permiso')->updateOrInsert(['rol_id' => $rolId, 'permiso_id' => $permIds[$cod]], []);
                }
            }
        }

        // Seed secuencia del año actual.
        DB::table('erp_secuencias_op')->updateOrInsert(
            ['empresa_id' => 1, 'anio' => (int) date('Y')],
            ['ultimo_numero' => (int) DB::table('erp_ordenes_pago')
                ->where('empresa_id', 1)
                ->where('numero', 'like', 'OP-' . date('Y') . '-%')
                ->count()],
        );
    }

    public function down(): void
    {
        DB::table('erp_rol_permiso')->whereIn('permiso_id', function ($q) {
            $q->select('id')->from('erp_permisos')->where('modulo', 'tesoreria')->where('entidad', 'op');
        })->delete();
        DB::table('erp_permisos')->where('modulo', 'tesoreria')->where('entidad', 'op')->delete();

        Schema::dropIfExists('erp_mapeo_bancos_distriapp');
        Schema::dropIfExists('erp_secuencias_op');
        Schema::dropIfExists('erp_ordenes_pago_audit');

        Schema::table('erp_ordenes_pago', function (Blueprint $table) {
            foreach (['fk_op_tipo_op', 'fk_op_cta_banco_pago'] as $fk) {
                try { $table->dropForeign($fk); } catch (\Throwable $e) {}
            }
            $table->dropColumn([
                'origen', 'distriapp_op_id', 'distriapp_concepto_id', 'distriapp_numero_correlativo',
                'tipo_op_id', 'beneficiario_snapshot', 'cotizacion_usd', 'importe_ars_equivalente',
                'medio_pago', 'cuenta_bancaria_pago_id', 'referencia_pago',
                'contabilizada', 'fecha_contabilizada', 'contabilizada_por_user_id',
                'sync_ultima_actualizacion', 'sync_hash', 'sync_payload_completo',
            ]);
        });
        Schema::dropIfExists('erp_ordenes_pago_tipos');
    }

    private function detectarTipoColumna(string $tabla, string $columna): string
    {
        $row = DB::selectOne(
            "SELECT DATA_TYPE as t FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1",
            [$tabla, $columna],
        );
        return $row && strtolower((string) $row->t) === 'int' ? 'int' : 'bigint';
    }

    private function crearIndiceSiFalta(string $tabla, string $nombre, string $tipo, string $columnas): void
    {
        $existe = DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$tabla, $nombre],
        );
        if ((int) ($existe->c ?? 0) === 0) {
            $unique = $tipo === 'UNIQUE' ? 'UNIQUE' : '';
            DB::statement("CREATE {$unique} INDEX {$nombre} ON {$tabla} ({$columnas})");
        }
    }

    private function crearFkSiFalta(string $tabla, string $nombre, string $col, string $refTabla, string $refCol): void
    {
        $existe = DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?",
            [$tabla, $nombre],
        );
        if ((int) ($existe->c ?? 0) === 0) {
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} FOREIGN KEY ({$col}) REFERENCES {$refTabla}({$refCol})");
        }
    }
};
