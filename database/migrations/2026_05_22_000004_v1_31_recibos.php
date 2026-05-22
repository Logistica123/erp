<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.31 — Recibos + imputación NC + retenciones.
 *
 * Cambios:
 *  1. erp_recibos: documento unificado que junta factura + NC aplicadas +
 *     retenciones + cobro. Reemplaza el flow de cobros sueltos del v1.15.
 *  2. erp_recibos_nc_aplicadas: NC aplicadas dentro de un recibo (auto-FIFO
 *     para WSFE o manual para otros).
 *  3. erp_recibos_retenciones: retenciones recibidas del cliente.
 *  4. 2 permisos: tesoreria.recibos.crear (super_admin, contador, tesorero,
 *     facturador) y tesoreria.recibos.anular (super_admin, contador).
 *  5. Migración retroactiva: cobros existentes (v1.15) → recibos BORRADOR
 *     con `migrado_de_cobro_id`. NO se re-emiten asientos (los del v1.15
 *     siguen vigentes), solo se materializa el recibo formal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // erp_cuentas_bancarias.id puede ser int (prod) o bigint (local) por
        // drift histórico. Detectamos el tipo real para tipear medio_cobro_id
        // igual y que el FK matchee en ambos entornos.
        $tipoCbId = $this->detectarTipoColumna('erp_cuentas_bancarias', 'id');

        Schema::create('erp_recibos', function (Blueprint $table) use ($tipoCbId) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('empresa_id');
            $table->string('numero_correlativo', 20)->unique()
                ->comment('Formato R-YYYY-NNNNNNNN');
            $table->date('fecha_emision');
            $table->unsignedBigInteger('cliente_auxiliar_id');
            $table->unsignedBigInteger('factura_venta_id')
                ->comment('Factura que cobra el recibo (MVP v1.31: 1 factura por recibo)');
            $table->decimal('total_factura', 18, 2);
            $table->decimal('total_nc_aplicadas', 18, 2)->default(0);
            $table->decimal('total_retenciones', 18, 2)->default(0);
            $table->decimal('monto_cobrable', 18, 2)
                ->comment('total_factura - NC - retenciones (clamp >= 0)');
            $table->decimal('monto_cobrado', 18, 2)->default(0)
                ->comment('Lo efectivamente cobrado. 0 si NC + ret cubren todo.');
            $table->decimal('saldo_factura_post', 18, 2)
                ->comment('Saldo de la factura DESPUÉS de aplicar este recibo');
            if ($tipoCbId === 'int') {
                $table->unsignedInteger('medio_cobro_id')->nullable()
                    ->comment('FK erp_cuentas_bancarias. NULL si monto_cobrado=0');
            } else {
                $table->unsignedBigInteger('medio_cobro_id')->nullable()
                    ->comment('FK erp_cuentas_bancarias. NULL si monto_cobrado=0');
            }
            $table->string('cae', 20)->nullable();
            $table->enum('estado', ['BORRADOR', 'EMITIDO', 'CONCILIADO', 'ANULADO'])
                ->default('BORRADOR');
            $table->unsignedBigInteger('asiento_id')->nullable();
            $table->unsignedBigInteger('mov_bancario_id')->nullable()
                ->comment('Set al conciliar contra extracto bancario (v1.27)');
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->dateTime('created_at');
            $table->dateTime('emitido_at')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->unsignedBigInteger('anulado_por_user_id')->nullable();
            $table->text('anulado_motivo')->nullable();
            // v1.31 §10 — Trazabilidad de migración retroactiva.
            $table->unsignedBigInteger('migrado_de_cobro_id')->nullable();
            $table->dateTime('migracion_fecha')->nullable();

            $table->index('factura_venta_id', 'idx_recibo_factura');
            $table->index('cliente_auxiliar_id', 'idx_recibo_cliente');
            $table->index('estado', 'idx_recibo_estado');
            $table->index('fecha_emision', 'idx_recibo_fecha');
            $table->foreign('factura_venta_id', 'fk_recibo_factura')
                ->references('id')->on('erp_facturas_venta');
            $table->foreign('cliente_auxiliar_id', 'fk_recibo_cliente')
                ->references('id')->on('erp_auxiliares');
            $table->foreign('medio_cobro_id', 'fk_recibo_medio')
                ->references('id')->on('erp_cuentas_bancarias');
            $table->foreign('asiento_id', 'fk_recibo_asiento')
                ->references('id')->on('erp_asientos');
        });

        Schema::create('erp_recibos_nc_aplicadas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('recibo_id');
            $table->unsignedBigInteger('nc_factura_id')
                ->comment('FK a erp_facturas_venta (tipo NOTA_CREDITO)');
            $table->decimal('monto_aplicado', 18, 2);
            $table->boolean('automatica')->default(false)
                ->comment('TRUE si la imputación fue auto-FIFO en WSFE');
            $table->dateTime('created_at')->useCurrent();

            $table->index('recibo_id', 'idx_rnc_recibo');
            $table->index('nc_factura_id', 'idx_rnc_nc');
            $table->foreign('recibo_id', 'fk_rnc_recibo')
                ->references('id')->on('erp_recibos')->cascadeOnDelete();
            $table->foreign('nc_factura_id', 'fk_rnc_nc')
                ->references('id')->on('erp_facturas_venta');
        });

        Schema::create('erp_recibos_retenciones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('recibo_id');
            $table->enum('tipo', ['GANANCIAS', 'IVA', 'IIBB', 'SUSS', 'OTRO']);
            $table->char('jurisdiccion_codigo', 3)->nullable()
                ->comment('Solo si tipo=IIBB');
            $table->string('numero_certificado', 40)->nullable();
            $table->decimal('alicuota', 5, 2)->nullable();
            $table->decimal('base_imponible', 18, 2)->nullable();
            $table->decimal('monto', 18, 2);
            $table->unsignedBigInteger('cuenta_contable_id')
                ->comment('Cuenta a la que va el crédito fiscal de la retención');
            $table->dateTime('created_at')->useCurrent();

            $table->index('recibo_id', 'idx_rret_recibo');
            $table->foreign('recibo_id', 'fk_rret_recibo')
                ->references('id')->on('erp_recibos')->cascadeOnDelete();
            $table->foreign('cuenta_contable_id', 'fk_rret_cuenta')
                ->references('id')->on('erp_cuentas_contables');
        });

        $perms = [
            ['codigo' => 'tesoreria.recibos.crear',
             'modulo' => 'tesoreria', 'entidad' => 'recibos', 'accion' => 'crear',
             'descripcion' => 'Permite crear y emitir recibos (cobranza unificada con NC + retenciones).',
             'sensible' => 0],
            ['codigo' => 'tesoreria.recibos.anular',
             'modulo' => 'tesoreria', 'entidad' => 'recibos', 'accion' => 'anular',
             'descripcion' => 'Permite anular recibos emitidos (reversa de asiento + liberación de saldos).',
             'sensible' => 1],
        ];
        foreach ($perms as $p) {
            DB::table('erp_permisos')->updateOrInsert(['codigo' => $p['codigo']], $p);
        }

        $rolesIds = DB::table('erp_roles')->get(['id', 'codigo'])->keyBy('codigo');
        $permIds = DB::table('erp_permisos')
            ->whereIn('codigo', ['tesoreria.recibos.crear', 'tesoreria.recibos.anular'])
            ->pluck('id', 'codigo');
        $matriz = [
            'super_admin' => ['tesoreria.recibos.crear', 'tesoreria.recibos.anular'],
            'contador'    => ['tesoreria.recibos.crear', 'tesoreria.recibos.anular'],
            'tesorero'    => ['tesoreria.recibos.crear'],
            'facturador'  => ['tesoreria.recibos.crear'],
        ];
        foreach ($matriz as $rolCod => $codigos) {
            $rolId = $rolesIds[$rolCod]->id ?? null;
            if (! $rolId) continue;
            foreach ($codigos as $cod) {
                $pid = $permIds[$cod] ?? null;
                if ($pid) {
                    DB::table('erp_rol_permiso')->updateOrInsert(
                        ['rol_id' => $rolId, 'permiso_id' => $pid], [],
                    );
                }
            }
        }

        // v1.31 §10 — Migración retroactiva de cobros existentes a recibos.
        // Cada cobro v1.15 con factura asociada se materializa como recibo
        // BORRADOR (no EMITIDO para no regenerar asiento — el del cobro ya
        // está vigente). El usuario podrá completar/emitir manualmente si
        // necesita el recibo formal.
        $cobros = DB::table('erp_cobros as c')
            ->join('erp_cobro_items as ci', 'ci.cobro_id', '=', 'c.id')
            ->join('erp_facturas_venta as fv', 'fv.id', '=', 'ci.factura_id')
            ->where('ci.tipo_item', 'FACTURA_VENTA')
            ->select('c.*', 'ci.factura_id', 'ci.importe as importe_imputado')
            ->get();

        foreach ($cobros as $cobro) {
            $existe = DB::table('erp_recibos')
                ->where('migrado_de_cobro_id', $cobro->id)
                ->exists();
            if ($existe) continue;

            $numero = sprintf('R-%s-%08d', substr($cobro->fecha, 0, 4), $cobro->id);
            $factura = DB::table('erp_facturas_venta')->where('id', $cobro->factura_id)->first();
            if (! $factura) continue;

            DB::table('erp_recibos')->insert([
                'empresa_id' => $cobro->empresa_id,
                'numero_correlativo' => $numero,
                'fecha_emision' => $cobro->fecha,
                'cliente_auxiliar_id' => $cobro->auxiliar_id,
                'factura_venta_id' => $cobro->factura_id,
                'total_factura' => $factura->imp_total,
                'total_nc_aplicadas' => 0,
                'total_retenciones' => $cobro->total_retenciones ?? 0,
                'monto_cobrable' => $cobro->importe_imputado,
                'monto_cobrado' => $cobro->importe_imputado,
                'saldo_factura_post' => max(0, (float) $factura->imp_total - (float) $cobro->importe_imputado),
                'medio_cobro_id' => null,
                'estado' => 'EMITIDO', // EMITIDO porque ya hay asiento del v1.15.
                'asiento_id' => $cobro->asiento_id,
                'observaciones' => sprintf('Migrado retroactivo del cobro #%s (%s)',
                    $cobro->id, $cobro->numero),
                'created_by_user_id' => $cobro->creado_por_user_id,
                'created_at' => $cobro->created_at,
                'emitido_at' => $cobro->created_at,
                'migrado_de_cobro_id' => $cobro->id,
                'migracion_fecha' => now(),
            ]);
        }
    }

    /**
     * Devuelve 'int' o 'bigint' según el DATA_TYPE de la columna en INFORMATION_SCHEMA.
     */
    private function detectarTipoColumna(string $tabla, string $columna): string
    {
        $row = DB::selectOne(
            "SELECT DATA_TYPE as t FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1",
            [$tabla, $columna],
        );
        return $row && strtolower((string) $row->t) === 'int' ? 'int' : 'bigint';
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_recibos_retenciones');
        Schema::dropIfExists('erp_recibos_nc_aplicadas');
        Schema::dropIfExists('erp_recibos');

        DB::table('erp_rol_permiso')->whereIn('permiso_id', function ($q) {
            $q->select('id')->from('erp_permisos')
                ->whereIn('codigo', ['tesoreria.recibos.crear', 'tesoreria.recibos.anular']);
        })->delete();
        DB::table('erp_permisos')
            ->whereIn('codigo', ['tesoreria.recibos.crear', 'tesoreria.recibos.anular'])
            ->delete();
    }
};
