<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.32 — Rediseño de Recibos al modelo de DistriApp.
 *
 * Cambios:
 *  1. ALTER `erp_recibos`: snapshot inmutable empresa+cliente + retenciones
 *     simples (IVA/IIBB/Ganancias) + numeración PV-NRO + detalle_cobro.
 *  2. Tabla nueva `erp_recibos_comprobantes_imputados` (rompe 1:1 con factura).
 *  3. Tabla nueva `erp_secuencias_recibo` con PV 0001 seeded con el max
 *     conocido (consulta cross-platform a basepersonal.liquidacion_recibos).
 *  4. Migración del recibo legacy `R-2026-00000001` → `0001-NNNNNNNN` con
 *     fila correspondiente en `erp_recibos_comprobantes_imputados`.
 *  5. Update `erp_empresas` (empresa_id=1) con datos reales del draft de
 *     DistriApp (idempotente: solo si el campo tiene placeholder).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) ALTER erp_recibos.
        Schema::table('erp_recibos', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_recibos', 'punto_venta')) {
                $table->char('punto_venta', 4)->nullable()->after('numero_correlativo');
            }
            if (! Schema::hasColumn('erp_recibos', 'numero')) {
                $table->string('numero', 20)->nullable()->after('punto_venta');
            }
            if (! Schema::hasColumn('erp_recibos', 'numero_legacy')) {
                $table->string('numero_legacy', 20)->nullable()
                    ->comment('R-YYYY-NNNNNNNN del v1.31, conservado para trazabilidad');
            }
            if (! Schema::hasColumn('erp_recibos', 'detalle_cobro')) {
                $table->string('detalle_cobro', 200)->nullable()
                    ->comment('Texto libre: ECHEQ N° X, transferencia, etc');
            }

            // Snapshot inmutable empresa al EMITIR.
            if (! Schema::hasColumn('erp_recibos', 'snapshot_empresa_razon_social')) {
                $table->string('snapshot_empresa_razon_social', 200)->nullable();
                $table->string('snapshot_empresa_cuit', 13)->nullable();
                $table->string('snapshot_empresa_direccion_1', 200)->nullable();
                $table->string('snapshot_empresa_direccion_2', 200)->nullable();
                $table->string('snapshot_empresa_condicion_iva', 40)->nullable();
                $table->date('snapshot_empresa_inicio_actividad')->nullable();
            }

            // Snapshot inmutable cliente al EMITIR.
            if (! Schema::hasColumn('erp_recibos', 'snapshot_cliente_razon_social')) {
                $table->string('snapshot_cliente_razon_social', 200)->nullable();
                $table->string('snapshot_cliente_cuit', 13)->nullable();
                $table->string('snapshot_cliente_direccion_1', 200)->nullable();
                $table->string('snapshot_cliente_direccion_2', 200)->nullable();
                $table->string('snapshot_cliente_condicion_iva', 40)->nullable();
            }

            // Retenciones formato simple (3 más comunes).
            if (! Schema::hasColumn('erp_recibos', 'retencion_iva_total')) {
                $table->decimal('retencion_iva_total', 18, 2)->default(0);
                $table->decimal('retencion_iibb_total', 18, 2)->default(0);
                $table->decimal('retencion_ganancias_total', 18, 2)->default(0);
            }
        });

        // Unique key PV+NRO (solo si no existe).
        $hasUk = DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'erp_recibos'
             AND index_name = 'uk_recibo_pv_nro'"
        );
        if ((int) ($hasUk->c ?? 0) === 0) {
            DB::statement('CREATE UNIQUE INDEX uk_recibo_pv_nro ON erp_recibos (punto_venta, numero)');
        }

        // 2) Tabla nueva: comprobantes imputados (rompe 1:1).
        if (! Schema::hasTable('erp_recibos_comprobantes_imputados')) {
            Schema::create('erp_recibos_comprobantes_imputados', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('recibo_id');
                $table->unsignedBigInteger('factura_venta_id');
                $table->decimal('monto_imputado', 18, 2);
                $table->decimal('total_factura', 18, 2)
                    ->comment('Snapshot al momento del recibo');
                $table->date('fecha_factura')
                    ->comment('Snapshot');
                $table->string('numero_factura_snapshot', 20)
                    ->comment('Snapshot PV-NRO al momento del recibo');
                $table->dateTime('created_at')->useCurrent();

                $table->index('recibo_id', 'idx_rci_recibo');
                $table->index('factura_venta_id', 'idx_rci_factura');
                $table->foreign('recibo_id', 'fk_rci_recibo')
                    ->references('id')->on('erp_recibos')->cascadeOnDelete();
                $table->foreign('factura_venta_id', 'fk_rci_factura')
                    ->references('id')->on('erp_facturas_venta');
            });
        }

        // 3) Secuencias de recibo por PV.
        if (! Schema::hasTable('erp_secuencias_recibo')) {
            Schema::create('erp_secuencias_recibo', function (Blueprint $table) {
                $table->char('punto_venta', 4)->primary();
                $table->unsignedBigInteger('ultimo_numero')->default(0);
                $table->enum('ultimo_emitido_por', ['ERP', 'DISTRIAPP'])->nullable();
                $table->dateTime('ultimo_emitido_at')->nullable();
                $table->text('observaciones')->nullable();
            });
        }

        // Seed PV 0001 con el max conocido. Consulta cross-platform a DistriApp
        // si la tabla existe + arranque local de v1.31.
        $maxLocal = (int) (DB::table('erp_recibos')
            ->where('punto_venta', '0001')
            ->max(DB::raw('CAST(numero AS UNSIGNED)')) ?? 0);
        $maxDistriapp = 0;
        try {
            $row = DB::selectOne("SELECT MAX(CAST(numero_recibo AS UNSIGNED)) m
                                   FROM basepersonal.liquidacion_recibos
                                   WHERE punto_venta = '0001'");
            $maxDistriapp = (int) ($row->m ?? 0);
        } catch (\Throwable $e) {
            // DistriApp no accesible — solo usa local.
        }
        $ultimoNumero = max($maxLocal, $maxDistriapp);
        DB::table('erp_secuencias_recibo')->updateOrInsert(
            ['punto_venta' => '0001'],
            [
                'ultimo_numero' => $ultimoNumero,
                'ultimo_emitido_por' => $maxDistriapp > $maxLocal ? 'DISTRIAPP' : 'ERP',
                'ultimo_emitido_at' => now(),
                'observaciones' => 'Seed inicial v1.32: max local='.$maxLocal.', max DistriApp='.$maxDistriapp,
            ],
        );

        // 4) Migración del recibo legacy R-YYYY-NNNNNNNN → 0001-NNNNNNNN.
        $legacys = DB::table('erp_recibos')
            ->where('numero_correlativo', 'LIKE', 'R-%')
            ->whereNull('numero')
            ->get();
        foreach ($legacys as $r) {
            // Asignar el siguiente número disponible del PV 0001.
            $proximo = (int) DB::table('erp_secuencias_recibo')
                ->where('punto_venta', '0001')
                ->lockForUpdate()
                ->value('ultimo_numero') + 1;
            $numero = str_pad((string) $proximo, 8, '0', STR_PAD_LEFT);
            DB::table('erp_recibos')->where('id', $r->id)->update([
                'punto_venta' => '0001',
                'numero' => $numero,
                'numero_legacy' => $r->numero_correlativo,
            ]);
            DB::table('erp_secuencias_recibo')
                ->where('punto_venta', '0001')
                ->update(['ultimo_numero' => $proximo, 'ultimo_emitido_at' => now()]);

            // Crear fila en comprobantes_imputados si el recibo tenía factura.
            if ($r->factura_venta_id) {
                $fv = DB::table('erp_facturas_venta as fv')
                    ->join('erp_puntos_venta as pv', 'pv.id', '=', 'fv.punto_venta_id')
                    ->where('fv.id', $r->factura_venta_id)
                    ->select('fv.numero', 'fv.fecha_emision', 'fv.imp_total', 'pv.numero as pv_numero')
                    ->first();
                if ($fv) {
                    $numFactura = sprintf('%04d-%08d', (int) $fv->pv_numero, (int) $fv->numero);
                    DB::table('erp_recibos_comprobantes_imputados')->insert([
                        'recibo_id' => $r->id,
                        'factura_venta_id' => $r->factura_venta_id,
                        'monto_imputado' => $r->monto_cobrado,
                        'total_factura' => $fv->imp_total,
                        'fecha_factura' => $fv->fecha_emision,
                        'numero_factura_snapshot' => $numFactura,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // 5) Update erp_empresas con datos reales (idempotente — solo si placeholder).
        DB::table('erp_empresas')
            ->where('id', 1)
            ->where(function ($q) {
                $q->where('cuit', '00000000000')
                    ->orWhere('domicilio_fiscal', 'A COMPLETAR')
                    ->orWhereNull('fecha_inicio_actividades');
            })
            ->update([
                'cuit' => '30717060985',
                'razon_social' => 'LOGISTICA ARGENTINA SRL',
                'nombre_fantasia' => 'Logística Argentina',
                'domicilio_fiscal' => 'SAN CAYETANO 3470, SAN CAYETANO - CORRIENTES',
                'fecha_inicio_actividades' => '2020-08-11',
                'condicion_iva' => 'RI',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_recibos_comprobantes_imputados');
        Schema::dropIfExists('erp_secuencias_recibo');

        Schema::table('erp_recibos', function (Blueprint $table) {
            $table->dropUnique('uk_recibo_pv_nro');
            $table->dropColumn([
                'punto_venta', 'numero', 'numero_legacy', 'detalle_cobro',
                'snapshot_empresa_razon_social', 'snapshot_empresa_cuit',
                'snapshot_empresa_direccion_1', 'snapshot_empresa_direccion_2',
                'snapshot_empresa_condicion_iva', 'snapshot_empresa_inicio_actividad',
                'snapshot_cliente_razon_social', 'snapshot_cliente_cuit',
                'snapshot_cliente_direccion_1', 'snapshot_cliente_direccion_2',
                'snapshot_cliente_condicion_iva',
                'retencion_iva_total', 'retencion_iibb_total', 'retencion_ganancias_total',
            ]);
        });
    }
};
