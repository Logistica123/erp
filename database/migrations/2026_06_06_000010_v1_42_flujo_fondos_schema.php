<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.42 Fase B — Flujo de Fondos matricial.
 *
 * 5 tablas: categorías jerárquicas, escenarios (Realista/Optimista/Pesimista),
 * líneas (celdas proyectado/real), snapshots de variance y calendarios
 * (cobros por cliente + fiscal recurrente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_flujo_categorias')) {
            Schema::create('erp_flujo_categorias', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('codigo', 40);
                $t->string('nombre', 150);
                $t->enum('tipo', ['INGRESO', 'EGRESO']);
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->integer('nivel')->default(0);
                $t->integer('orden_presentacion')->default(0);
                $t->unsignedBigInteger('cuenta_contable_id')->nullable();
                $t->boolean('auto_calculable')->default(false);
                $t->text('formula_calculo')->nullable();
                $t->boolean('activa')->default(true);
                $t->unique(['empresa_id', 'codigo'], 'uk_flujo_cat_codigo');
                $t->index('parent_id', 'idx_flujo_cat_parent');
            });
        }

        if (! Schema::hasTable('erp_flujo_escenarios')) {
            Schema::create('erp_flujo_escenarios', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('nombre', 100);
                $t->enum('tipo', ['REALISTA', 'OPTIMISTA', 'PESIMISTA', 'CUSTOM']);
                $t->smallInteger('anio');
                $t->enum('estado', ['BORRADOR', 'VIGENTE', 'CERRADO'])->default('BORRADOR');
                $t->text('descripcion')->nullable();
                $t->boolean('es_default')->default(false);
                $t->unsignedBigInteger('creado_por_user_id');
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['empresa_id', 'nombre', 'anio'], 'uk_flujo_esc_empresa_nombre_anio');
            });
        }

        if (! Schema::hasTable('erp_flujo_lineas')) {
            Schema::create('erp_flujo_lineas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('escenario_id');
                $t->unsignedBigInteger('categoria_id');
                $t->date('fecha');
                $t->smallInteger('anio');
                $t->tinyInteger('mes');
                $t->tinyInteger('semana_iso');
                $t->tinyInteger('semana_mes');
                $t->decimal('importe_proyectado', 18, 2)->default(0);
                $t->decimal('importe_real', 18, 2)->nullable();
                $t->string('moneda', 3)->default('ARS');
                $t->enum('origen', [
                    'PROYECCION_AUTO_FACTURA', 'PROYECCION_AUTO_OP', 'PROYECCION_AUTO_CALENDARIO',
                    'PROYECCION_MANUAL', 'REAL_AUTO_RECIBO', 'REAL_AUTO_OP', 'REAL_AUTO_EXTRACTO',
                    'REAL_AUTO_CAJA', 'REAL_AUTO_INVERSION', 'REAL_AUTO_PRESTAMO', 'REAL_MANUAL',
                ]);
                $t->unsignedBigInteger('auxiliar_id')->nullable();
                $t->unsignedBigInteger('factura_id')->nullable();
                $t->unsignedBigInteger('recibo_id')->nullable();
                $t->unsignedBigInteger('op_id')->nullable();
                $t->unsignedBigInteger('extracto_movimiento_id')->nullable();
                $t->unsignedBigInteger('caja_movimiento_id')->nullable();
                $t->unsignedBigInteger('inversion_movimiento_id')->nullable();
                $t->unsignedBigInteger('prestamo_cuota_id')->nullable();
                $t->boolean('override_manual')->default(false);
                $t->text('motivo_override')->nullable();
                $t->unsignedBigInteger('override_por_user_id')->nullable();
                $t->dateTime('fecha_override')->nullable();
                $t->text('observaciones')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->index(['escenario_id', 'fecha'], 'idx_flujo_lin_esc_fecha');
                $t->index('categoria_id', 'idx_flujo_lin_cat');
                $t->index(['escenario_id', 'anio', 'semana_iso'], 'idx_flujo_lin_semana');
                $t->index(['escenario_id', 'anio', 'mes'], 'idx_flujo_lin_mes');
                $t->index('origen', 'idx_flujo_lin_origen');
            });
        }

        if (! Schema::hasTable('erp_flujo_variance_snapshots')) {
            Schema::create('erp_flujo_variance_snapshots', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('escenario_id');
                $t->date('fecha_snapshot');
                $t->smallInteger('anio');
                $t->tinyInteger('semana_iso');
                $t->unsignedBigInteger('categoria_id');
                $t->decimal('importe_proyectado_original', 18, 2);
                $t->decimal('importe_real_capturado', 18, 2);
                $t->decimal('variance_absoluto', 18, 2);
                $t->decimal('variance_porcentaje', 8, 2);
                $t->text('motivo_variance')->nullable();
                $t->unique(['escenario_id', 'anio', 'semana_iso', 'categoria_id'], 'uk_flujo_var_snapshot');
                $t->index('fecha_snapshot', 'idx_flujo_var_fecha');
            });
        }

        if (! Schema::hasTable('erp_flujo_calendario_cobros')) {
            Schema::create('erp_flujo_calendario_cobros', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('auxiliar_id');
                $t->enum('periodicidad', ['QUINCENAL', 'MENSUAL', 'EVENTUAL']);
                $t->tinyInteger('dia_cobro_1q')->nullable();
                $t->tinyInteger('dia_cobro_2q')->nullable();
                $t->tinyInteger('dia_cobro_mensual')->nullable();
                $t->integer('plazo_post_cierre_dias')->nullable();
                $t->decimal('porcentaje_q1', 5, 2)->nullable();
                $t->decimal('porcentaje_q2', 5, 2)->nullable();
                $t->text('observaciones')->nullable();
                $t->boolean('activo')->default(true);
                $t->unique('auxiliar_id', 'uk_flujo_cal_cob_aux');
            });
        }

        if (! Schema::hasTable('erp_flujo_calendario_fiscal')) {
            Schema::create('erp_flujo_calendario_fiscal', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('categoria_id');
                $t->string('nombre', 100);
                $t->enum('periodicidad', ['MENSUAL', 'BIMENSUAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL']);
                $t->tinyInteger('dia_vencimiento');
                $t->decimal('importe_referencial', 18, 2)->nullable();
                $t->boolean('activo')->default(true);
            });
        }

        $this->seedCategorias();
        $this->seedCalendarioFiscal();
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_flujo_calendario_fiscal');
        Schema::dropIfExists('erp_flujo_calendario_cobros');
        Schema::dropIfExists('erp_flujo_variance_snapshots');
        Schema::dropIfExists('erp_flujo_lineas');
        Schema::dropIfExists('erp_flujo_escenarios');
        Schema::dropIfExists('erp_flujo_categorias');
    }

    private function seedCategorias(): void
    {
        $empresaId = DB::table('erp_empresas')->orderBy('id')->value('id');
        if (! $empresaId) return;
        if (DB::table('erp_flujo_categorias')->where('empresa_id', $empresaId)->exists()) return;

        // Estructura {codigo, nombre, tipo, hijos:[{codigo, nombre}]}
        $tree = [
            // INGRESOS
            ['ING-COB-OP', 'Cobros operativos por cliente', 'INGRESO', [
                ['COB-URBANO', 'Cobros Urbano'],
                ['COB-OCASA-70', 'Cobros Ocasa 70%'],
                ['COB-OCASA-30', 'Cobros Ocasa 30%'],
                ['COB-VITAL-TRASPASO', 'Cobros Vital Traspaso'],
                ['COB-VITAL-REPARTO', 'Cobros Vital Reparto'],
                ['COB-LOGINTER', 'Cobros Loginter'],
                ['COB-OCA', 'Cobros OCA'],
                ['COB-ANDESMAR', 'Cobros Andesmar'],
                ['COB-QX', 'Cobros QX'],
                ['COB-FLASH', 'Cobros Flash'],
            ]],
            ['ING-COB-CIRCUITOS', 'Cierres de circuitos especiales', 'INGRESO', []],
            ['ING-COB-EFECTIVO', 'Cobros operaciones en efectivo', 'INGRESO', []],
            ['ING-INV-RESC-FCI', 'Rescate FCI', 'INGRESO', []],
            ['ING-INV-RESC-PF', 'Rescate Plazo Fijo', 'INGRESO', []],
            ['ING-PRESTAMOS', 'Préstamos recibidos', 'INGRESO', []],
            ['ING-OTROS', 'Otros ingresos', 'INGRESO', []],
            // EGRESOS
            ['EGR-SUE', 'Sueldos y cargas sociales', 'EGRESO', [
                ['SUE-SUELDOS-BCO', 'Sueldos banco'],
                ['SUE-SAC', 'SAC (aguinaldo)'],
                ['SUE-EFECTIVO', 'Sueldos efectivo'],
                ['SUE-SUSS-931', 'SUSS F.931'],
                ['SUE-SINDICATO', 'Sindicato'],
                ['SUE-MEDICINA', 'Medicina prepaga'],
                ['SUE-EMBARGOS', 'Embargos'],
                ['SUE-TERCERIZADOS', 'Tercerizados'],
            ]],
            ['EGR-IMP', 'Impuestos', 'EGRESO', [
                ['IMP-IIBB', 'IIBB'],
                ['IMP-IVA-MENSUAL', 'IVA mensual'],
                ['IMP-ANT-GAN', 'Anticipos Ganancias'],
                ['IMP-DEB-CRED', 'Débitos y créditos'],
                ['IMP-TASA-HIG', 'Tasa de higiene'],
                ['IMP-SELLADOS', 'Sellados'],
                ['IMP-OTROS', 'Otros impuestos'],
            ]],
            ['EGR-PRE', 'Préstamos y planes de pago', 'EGRESO', [
                ['PRE-AFIP', 'Plan AFIP'],
                ['PRE-RENTAS', 'Plan Rentas'],
                ['PRE-BCO', 'Préstamo bancario'],
                ['PRE-PARTICULARES', 'Préstamos particulares'],
                ['PRE-TARJ', 'Tarjetas'],
            ]],
            ['EGR-DIS', 'Pago a distribuidores', 'EGRESO', [
                ['DIS-URBANO', 'Pago Urbano'],
                ['DIS-OCASA', 'Pago Ocasa'],
                ['DIS-VITAL', 'Pago Vital'],
                ['DIS-OCA', 'Pago OCA'],
                ['DIS-LOGINTER', 'Pago Loginter'],
                ['DIS-ANDESMAR', 'Pago Andesmar'],
            ]],
            ['EGR-COS', 'Costos fijos', 'EGRESO', [
                ['COS-ALQUILER', 'Alquileres'],
                ['COS-COMBUSTIBLE', 'Combustible'],
                ['COS-SEGUROS', 'Seguros'],
                ['COS-TELEFONIA', 'Telefonía / internet'],
                ['COS-SERV-PUB', 'Servicios públicos'],
            ]],
            ['EGR-INV-SUSC', 'Suscripciones a inversiones', 'EGRESO', [
                ['INV-SUSC-FCI', 'Suscripción FCI'],
                ['INV-SUSC-PF', 'Suscripción Plazo Fijo'],
            ]],
            ['EGR-RETIRO-SOCIO', 'Retiro socio', 'EGRESO', []],
        ];

        $orden = 10;
        foreach ($tree as $parent) {
            $parentId = DB::table('erp_flujo_categorias')->insertGetId([
                'empresa_id' => $empresaId, 'codigo' => $parent[0], 'nombre' => $parent[1],
                'tipo' => $parent[2], 'parent_id' => null, 'nivel' => 0,
                'orden_presentacion' => $orden, 'activa' => true,
            ]);
            $orden += 10;
            $subOrden = 10;
            foreach ($parent[3] as $hijo) {
                DB::table('erp_flujo_categorias')->insert([
                    'empresa_id' => $empresaId, 'codigo' => $hijo[0], 'nombre' => $hijo[1],
                    'tipo' => $parent[2], 'parent_id' => $parentId, 'nivel' => 1,
                    'orden_presentacion' => $subOrden, 'activa' => true,
                    'auto_calculable' => str_starts_with($hijo[0], 'COB-'),
                ]);
                $subOrden += 10;
            }
        }
    }

    private function seedCalendarioFiscal(): void
    {
        $empresaId = DB::table('erp_empresas')->orderBy('id')->value('id');
        if (! $empresaId) return;

        $impMap = [
            'IMP-IIBB' => ['IIBB Mensual', 'MENSUAL', 15],
            'IMP-IVA-MENSUAL' => ['IVA Mensual', 'MENSUAL', 20],
            'IMP-ANT-GAN' => ['Anticipos Ganancias', 'TRIMESTRAL', 25],
            'IMP-DEB-CRED' => ['Débitos y Créditos', 'MENSUAL', 10],
            'IMP-TASA-HIG' => ['Tasa de Higiene', 'MENSUAL', 5],
        ];
        foreach ($impMap as $codigo => [$nombre, $period, $dia]) {
            $catId = DB::table('erp_flujo_categorias')
                ->where('empresa_id', $empresaId)->where('codigo', $codigo)->value('id');
            if (! $catId) continue;
            $exists = DB::table('erp_flujo_calendario_fiscal')
                ->where('categoria_id', $catId)->where('nombre', $nombre)->exists();
            if ($exists) continue;
            DB::table('erp_flujo_calendario_fiscal')->insert([
                'categoria_id' => $catId, 'nombre' => $nombre,
                'periodicidad' => $period, 'dia_vencimiento' => $dia,
                'activo' => true,
            ]);
        }
    }
};
