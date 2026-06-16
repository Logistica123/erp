<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.48 — Cierre módulo Tesorería. Schema delta.
 *
 * Adaptaciones a la realidad (el schema_v148_delta.sql trae mismatches):
 *  - auxiliares.razon_social_normalizada se genera de `nombre` (no `razon_social`).
 *  - regla nueva usa cols reales: banco_id, patron_concepto, orden_prioridad.
 *  - reusa cuit_contraparte/nombre_contraparte existentes (no _extracto).
 *  - estado: preserva el set real + suma PENDIENTE_TRANSF_INTERNA / CONFIRMADO_TRANSF_INTERNA.
 *  - las 3 cuentas se crean ANTES del seed de motivos (que las referencia).
 */
return new class extends Migration
{
    private const EMPRESA_ID = 1;

    public function up(): void
    {
        $this->crearCuentas();
        $this->crearTablaMotivos();
        $this->seedMotivos();
        $this->columnasMovimientos();
        $this->estadosMovimientos();
        $this->columnasReglas();
        $this->razonSocialNormalizada();
        $this->reglaNuevaTrYActivarNombre();
    }

    private function crearCuentas(): void
    {
        $nuevas = [
            ['4.3.04', 'Diferencias de Conciliación a Favor', '4.3', 'RP', 'Otros Ingresos', 0, null, 'DIF-CONC+', 'ACREEDOR'],
            ['4.3.05', 'Multas a Distribuidores', '4.3', 'RP', 'Otros Ingresos', 1, 'Distribuidor', 'MULTAS-DIST', 'ACREEDOR'],
            ['5.6.05', 'Diferencias de Conciliación en Contra', '5.6', 'RN', 'Otros Egresos', 0, null, 'DIF-CONC-', 'DEUDOR'],
        ];
        foreach ($nuevas as [$codigo, $nombre, $padre, $tipo, $rubro, $admiteAux, $tipoAux, $etiqueta, $saldoNormal]) {
            if (DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->exists()) continue;
            $padreId = DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $padre)->value('id');
            DB::table('erp_cuentas_contables')->insert([
                'empresa_id' => self::EMPRESA_ID, 'codigo' => $codigo, 'codigo_padre_id' => $padreId,
                'nivel' => 4, 'nombre' => $nombre, 'tipo' => $tipo, 'rubro_ec' => $rubro,
                'imputable' => 1, 'moneda' => 'ARS', 'admite_cc' => 0, 'admite_auxiliar' => $admiteAux,
                'tipo_auxiliar' => $tipoAux, 'etiqueta_cierre' => $etiqueta, 'saldo_normal' => $saldoNormal,
                'regularizadora' => 0, 'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function crearTablaMotivos(): void
    {
        if (Schema::hasTable('erp_conciliacion_motivos')) return;
        Schema::create('erp_conciliacion_motivos', function (Blueprint $t) {
            $t->increments('id');
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 150);
            $t->unsignedBigInteger('cuenta_ajuste_id')->nullable();
            $t->enum('tipo', ['DEFINITIVO', 'ANTICIPO_PROVEEDOR', 'MANUAL'])->default('DEFINITIVO');
            $t->enum('signo_esperado', ['+', '-', 'AMBOS'])->default('AMBOS');
            $t->string('requiere_auxiliar_tipo', 30)->nullable();
            $t->integer('orden_visual')->default(100);
            $t->string('observaciones', 255)->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index(['activo', 'orden_visual'], 'idx_motivos_activo');
        });
    }

    private function seedMotivos(): void
    {
        $cta = fn (string $c) => DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $c)->value('id');
        $motivos = [
            ['RET-GAN', 'Retención Ganancias practicada', '2.1.3.04', 'DEFINITIVO', '-', null, 10, 'Cliente le retiene Ganancias al pagar'],
            ['RET-IVA', 'Retención IVA practicada', '2.1.3.03', 'DEFINITIVO', '-', null, 20, null],
            ['RET-SUSS', 'Retención SUSS practicada', '2.1.3.05', 'DEFINITIVO', '-', null, 30, null],
            ['PERC-IIBB-CABA', 'Percepción IIBB - CABA practicada', '2.1.3.06', 'DEFINITIVO', '-', null, 40, null],
            ['PERC-IIBB-PBA', 'Percepción IIBB - PBA practicada', '2.1.3.07', 'DEFINITIVO', '-', null, 50, null],
            ['ANTICIPO-PROV', 'Anticipo a proveedor descontado', '1.1.5.01', 'DEFINITIVO', '-', 'Proveedor', 60, 'Cancela el anticipo otorgado previamente'],
            ['RECUP-GAST', 'Recupero de gastos pagados por la empresa', '4.3.01', 'DEFINITIVO', '-', null, 70, 'Combustible, peajes adelantados'],
            ['PREST-EMP', 'Préstamo al personal descontado', '1.1.5.10', 'DEFINITIVO', '-', 'Empleado', 80, null],
            ['CC-COMB', 'CC combustible personal descontado', '1.1.5.11', 'DEFINITIVO', '-', 'Empleado', 90, null],
            ['CC-POLIZA', 'CC pólizas personal descontado', '1.1.5.12', 'DEFINITIVO', '-', 'Empleado', 100, null],
            ['CC-SANCION', 'CC sanciones personal descontado', '1.1.5.13', 'DEFINITIVO', '-', 'Empleado', 110, null],
            ['MULTA-DIST', 'Multa al Distribuidor', '4.3.05', 'DEFINITIVO', '-', 'Distribuidor', 120, null],
            ['DIF-FAVOR', 'Diferencia menor a favor (banco pagó menos)', '4.3.04', 'DEFINITIVO', '-', null, 130, 'Diferencia residual sin causa identificada'],
            ['FALTA-FACT', 'Falta facturación — queda como anticipo', '1.1.5.01', 'ANTICIPO_PROVEEDOR', '+', 'Proveedor', 140, 'Distribuidor debe emitir NC complementaria'],
            ['DIF-CONTRA', 'Diferencia menor en contra (banco pagó más)', '5.6.05', 'DEFINITIVO', '+', null, 150, null],
            ['OTRO', 'Otro (manual)', null, 'MANUAL', 'AMBOS', null, 999, 'Operador elige cuenta libre del plan'],
        ];
        foreach ($motivos as [$codigo, $nombre, $ctaCod, $tipo, $signo, $reqAux, $orden, $obs]) {
            DB::table('erp_conciliacion_motivos')->updateOrInsert(['codigo' => $codigo], [
                'nombre' => $nombre,
                'cuenta_ajuste_id' => $ctaCod ? $cta($ctaCod) : null,
                'tipo' => $tipo, 'signo_esperado' => $signo, 'requiere_auxiliar_tipo' => $reqAux,
                'orden_visual' => $orden, 'observaciones' => $obs, 'activo' => 1,
            ]);
        }
    }

    private function columnasMovimientos(): void
    {
        Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
            $add = [
                'motivo_diferencia_id' => fn () => $t->unsignedInteger('motivo_diferencia_id')->nullable(),
                'pendiente_factura_complementaria' => fn () => $t->boolean('pendiente_factura_complementaria')->default(false),
                'distribuidor_pendiente_id' => fn () => $t->unsignedBigInteger('distribuidor_pendiente_id')->nullable(),
                'monto_pendiente_facturar' => fn () => $t->decimal('monto_pendiente_facturar', 18, 2)->nullable(),
                'nc_complementaria_id' => fn () => $t->unsignedBigInteger('nc_complementaria_id')->nullable(),
                'observaciones_pendiente' => fn () => $t->string('observaciones_pendiente', 500)->nullable(),
                'es_transferencia_interna' => fn () => $t->boolean('es_transferencia_interna')->default(false),
                'mov_espejo_id' => fn () => $t->unsignedBigInteger('mov_espejo_id')->nullable(),
            ];
            foreach ($add as $col => $fn) {
                if (! Schema::hasColumn('erp_movimientos_bancarios', $col)) $fn();
            }
        });
        Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
            $t->index('pendiente_factura_complementaria', 'idx_pendiente_factura');
            $t->index(['es_transferencia_interna', 'mov_espejo_id'], 'idx_transf_interna');
        });
    }

    private function estadosMovimientos(): void
    {
        DB::statement("ALTER TABLE erp_movimientos_bancarios MODIFY COLUMN estado ENUM(
            'PENDIENTE','ETIQUETADO','MATCH_AUTO','CONFIRMADO','REVERTIDO','CONCILIADO','CONCILIADO_MANUAL','IGNORADO',
            'EN_LOTE','CONFIRMADO_EN_LOTE','PENDIENTE_TRANSF_INTERNA','CONFIRMADO_TRANSF_INTERNA'
        ) NOT NULL DEFAULT 'PENDIENTE'");
    }

    private function columnasReglas(): void
    {
        Schema::table('erp_conciliacion_reglas', function (Blueprint $t) {
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'matching_auto_por_nombre')) {
                $t->boolean('matching_auto_por_nombre')->default(false);
            }
            if (! Schema::hasColumn('erp_conciliacion_reglas', 'nombre_extractor_regex')) {
                $t->string('nombre_extractor_regex', 500)->nullable();
            }
        });
    }

    private function razonSocialNormalizada(): void
    {
        if (Schema::hasColumn('erp_auxiliares', 'razon_social_normalizada')) return;
        // GENERATED STORED desde `nombre` (la columna real). MySQL 8 ok.
        // Debe coincidir byte a byte con MatchingAutoService::normalizar():
        // UPPER → sin tildes/Ñ/Ü → quitar todo lo no [A-Z0-9 ] → colapsar espacios → trim.
        DB::statement("ALTER TABLE erp_auxiliares
            ADD COLUMN razon_social_normalizada VARCHAR(255) GENERATED ALWAYS AS (
                TRIM(REGEXP_REPLACE(
                    REGEXP_REPLACE(
                        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                            UPPER(nombre)
                        ,'Á','A'),'É','E'),'Í','I'),'Ó','O'),'Ú','U'),'Ñ','N'),'Ü','U')
                    ,'[^A-Z0-9 ]','')
                ,' +',' '))
            ) STORED");
        DB::statement('CREATE INDEX idx_aux_nombre_norm ON erp_auxiliares (razon_social_normalizada)');
    }

    private function reglaNuevaTrYActivarNombre(): void
    {
        // Activar matching por nombre en reglas Brubank/MP.
        DB::table('erp_conciliacion_reglas')
            ->whereIn('codigo', ['BR-ECHEQ-RECIB', 'BR-CHQ-DEPOSITO', 'MP-INGRESO-DINERO'])
            ->update(['matching_auto_por_nombre' => 1]);
        DB::table('erp_conciliacion_reglas')->where('codigo', 'MP-TRANSF-ENVIADA-GEN')
            ->update(['matching_auto_por_nombre' => 1, 'nombre_extractor_regex' => 'Transferencia\s+enviada\s+(.+)$']);

        // Regla nueva ICBC-TRANSF-CTA-NOMINA (cols reales).
        if (! DB::table('erp_conciliacion_reglas')->where('codigo', 'ICBC-TRANSF-CTA-NOMINA')->exists()) {
            DB::table('erp_conciliacion_reglas')->insert([
                'empresa_id' => self::EMPRESA_ID,
                'codigo' => 'ICBC-TRANSF-CTA-NOMINA',
                'descripcion' => 'Transferencias a cuenta nómina (TR.XXXX A NNNN/NNNNNNNN/NN) — contraparte por CUIT/nombre.',
                'tipo' => 'CONCEPTO_REGEX',
                'patron_concepto' => 'TR\.\d+\s+A\s+\d{4}/\d{8}/\d{2}',
                'cuenta_contable_id' => null,
                'cuenta_contable_modo' => 'DINAMICO_POR_AUXILIAR',
                'tipo_auxiliar' => 'EMPLEADO',
                'matching_auto_factura' => 1,
                'matching_auto_por_nombre' => 1,
                'banco_id' => null,
                'signo' => 'DEBITO',
                'orden_prioridad' => 80,
                'confianza' => 75,
                'activa' => 1,
                'observacion' => '[v1.48]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('erp_movimientos_bancarios', function (Blueprint $t) {
            foreach (['motivo_diferencia_id', 'pendiente_factura_complementaria', 'distribuidor_pendiente_id',
                'monto_pendiente_facturar', 'nc_complementaria_id', 'observaciones_pendiente',
                'es_transferencia_interna', 'mov_espejo_id'] as $c) {
                if (Schema::hasColumn('erp_movimientos_bancarios', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('erp_conciliacion_reglas', function (Blueprint $t) {
            foreach (['matching_auto_por_nombre', 'nombre_extractor_regex'] as $c) {
                if (Schema::hasColumn('erp_conciliacion_reglas', $c)) $t->dropColumn($c);
            }
        });
        if (Schema::hasColumn('erp_auxiliares', 'razon_social_normalizada')) {
            DB::statement('ALTER TABLE erp_auxiliares DROP COLUMN razon_social_normalizada');
        }
        Schema::dropIfExists('erp_conciliacion_motivos');
    }
};
