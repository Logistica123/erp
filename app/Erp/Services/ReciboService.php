<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\Recibo;
use App\Erp\Models\Tesoreria\ReciboNcAplicada;
use App\Erp\Models\Tesoreria\ReciboRetencion;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v1.31 — Servicio de Recibos. Centraliza la cobranza: factura + NC aplicadas
 * + retenciones + cobro neto en un único documento.
 *
 * Estados: BORRADOR → EMITIDO (con asiento) → CONCILIADO (vs banco) o ANULADO.
 */
class ReciboService
{
    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * RN-31-1 — Crea un recibo en estado BORRADOR.
     *
     * @param  array{
     *   factura_venta_id:int,
     *   fecha_emision?:string,
     *   nc_aplicadas?:list<array{nc_factura_id:int, monto_aplicado:float, automatica?:bool}>,
     *   retenciones?:list<array{tipo:string, jurisdiccion_codigo?:?string, numero_certificado?:?string,
     *                            alicuota?:?float, base_imponible?:?float, monto:float, cuenta_contable_id:int}>,
     *   monto_cobrado?:float,
     *   medio_cobro_id?:?int,
     *   observaciones?:?string,
     *   auto_imputar_nc?:bool,
     * }  $data
     */
    public function crear(array $data, User $usuario, int $empresaId = 1): Recibo
    {
        return DB::transaction(function () use ($data, $usuario, $empresaId) {
            $factura = FacturaVenta::where('empresa_id', $empresaId)
                ->where('id', $data['factura_venta_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Si la factura es WSFE (origen=EMITIDA) y el operador no pasó NC, auto-imputar FIFO.
            $ncAplicadas = $data['nc_aplicadas'] ?? [];
            if (empty($ncAplicadas) && ($data['auto_imputar_nc'] ?? true)
                && in_array($factura->origen, ['EMITIDA', 'WSFE_ERP'], true)) {
                $ncAplicadas = $this->autoImputarNcFifo($factura, $empresaId);
            }

            // Validar saldos de cada NC.
            foreach ($ncAplicadas as $i => $nc) {
                $saldo = $this->saldoImputableNc((int) $nc['nc_factura_id'], $empresaId);
                if ((float) $nc['monto_aplicado'] > $saldo + 0.01) {
                    throw new DomainException(sprintf(
                        'NC_SOBRE_IMPUTADA: NC #%d tiene saldo imputable $%.2f pero se intenta aplicar $%.2f',
                        $nc['nc_factura_id'], $saldo, $nc['monto_aplicado'],
                    ));
                }
            }

            $retenciones = $data['retenciones'] ?? [];

            $totalNc = array_sum(array_map(fn ($n) => (float) $n['monto_aplicado'], $ncAplicadas));
            $totalRet = array_sum(array_map(fn ($r) => (float) $r['monto'], $retenciones));
            $totalFactura = (float) $factura->imp_total;
            $montoCobrable = max(0.0, $totalFactura - $totalNc - $totalRet);
            $montoCobrado = isset($data['monto_cobrado'])
                ? (float) $data['monto_cobrado']
                : $montoCobrable;

            if ($montoCobrado > $montoCobrable + 0.01) {
                throw new DomainException(sprintf(
                    'MONTO_COBRADO_EXCEDE_COBRABLE: $%.2f > $%.2f',
                    $montoCobrado, $montoCobrable,
                ));
            }
            if ($montoCobrado > 0 && empty($data['medio_cobro_id'])) {
                throw new DomainException('MEDIO_COBRO_REQUERIDO: el monto cobrado > 0 requiere medio_cobro_id.');
            }

            $saldoAnterior = $this->saldoFactura($factura->id, $empresaId);
            $saldoPost = round($saldoAnterior - ($montoCobrado + $totalNc + $totalRet), 2);
            if ($saldoPost < -0.01) {
                throw new DomainException(sprintf(
                    'SALDO_NEGATIVO: aplicar este recibo dejaría la factura con saldo $%.2f', $saldoPost,
                ));
            }

            $recibo = Recibo::create([
                'empresa_id' => $empresaId,
                'numero_correlativo' => $this->siguienteNumero($empresaId, $data['fecha_emision'] ?? null),
                'fecha_emision' => $data['fecha_emision'] ?? today()->toDateString(),
                'cliente_auxiliar_id' => $factura->auxiliar_id,
                'factura_venta_id' => $factura->id,
                'total_factura' => $totalFactura,
                'total_nc_aplicadas' => round($totalNc, 2),
                'total_retenciones' => round($totalRet, 2),
                'monto_cobrable' => round($montoCobrable, 2),
                'monto_cobrado' => round($montoCobrado, 2),
                'saldo_factura_post' => max(0.0, $saldoPost),
                'medio_cobro_id' => $data['medio_cobro_id'] ?? null,
                'estado' => Recibo::ESTADO_BORRADOR,
                'observaciones' => $data['observaciones'] ?? null,
                'created_by_user_id' => $usuario->id,
                'created_at' => now(),
            ]);

            foreach ($ncAplicadas as $nc) {
                ReciboNcAplicada::create([
                    'recibo_id' => $recibo->id,
                    'nc_factura_id' => (int) $nc['nc_factura_id'],
                    'monto_aplicado' => round((float) $nc['monto_aplicado'], 2),
                    'automatica' => (bool) ($nc['automatica'] ?? false),
                    'created_at' => now(),
                ]);
            }
            foreach ($retenciones as $r) {
                ReciboRetencion::create([
                    'recibo_id' => $recibo->id,
                    'tipo' => $r['tipo'],
                    'jurisdiccion_codigo' => $r['jurisdiccion_codigo'] ?? null,
                    'numero_certificado' => $r['numero_certificado'] ?? null,
                    'alicuota' => $r['alicuota'] ?? null,
                    'base_imponible' => $r['base_imponible'] ?? null,
                    'monto' => round((float) $r['monto'], 2),
                    'cuenta_contable_id' => (int) $r['cuenta_contable_id'],
                    'created_at' => now(),
                ]);
            }

            $this->audit->logEvento(
                accion: 'RECIBO_CREADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Recibo %s creado (factura #%d, total $%.2f, NC $%.2f, ret $%.2f, cobrado $%.2f)',
                    $recibo->numero_correlativo, $factura->id, $totalFactura, $totalNc, $totalRet, $montoCobrado),
                empresaId: $empresaId,
            );

            return $recibo->fresh(['ncAplicadas', 'retenciones']);
        });
    }

    /**
     * RN-31-2 — Emite el recibo (BORRADOR → EMITIDO + genera asiento).
     */
    public function emitir(Recibo $recibo, User $usuario): Asiento
    {
        if ($recibo->estado !== Recibo::ESTADO_BORRADOR) {
            throw new DomainException(sprintf(
                'ESTADO_INVALIDO: solo se emiten recibos en BORRADOR (actual: %s)', $recibo->estado,
            ));
        }

        return DB::transaction(function () use ($recibo, $usuario) {
            $empresaId = $recibo->empresa_id;
            $diarioId = DB::table('erp_diarios')
                ->where('empresa_id', $empresaId)->where('codigo', 'TES')->value('id')
                ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
            if (! $diarioId) throw new RuntimeException('Diario TES/GEN no existe.');

            $ccGeneral = DB::table('erp_centros_costo')
                ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
            $cuentaDeudoresId = DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)->where('codigo', '1.1.4.01')->value('id');
            if (! $cuentaDeudoresId) throw new RuntimeException('Cuenta 1.1.4.01 (Deudores por ventas) no existe.');

            $movimientos = [];

            // Débito: medio_cobro por monto_cobrado.
            if ((float) $recibo->monto_cobrado > 0) {
                $cuentaMedioId = DB::table('erp_cuentas_bancarias')
                    ->where('id', $recibo->medio_cobro_id)
                    ->value('cuenta_contable_id');
                if (! $cuentaMedioId) throw new RuntimeException('Medio de cobro sin cuenta contable.');
                $movimientos[] = [
                    'cuenta_id' => $cuentaMedioId,
                    'centro_costo_id' => $this->admiteCc((int) $cuentaMedioId) ? $ccGeneral : null,
                    'auxiliar_id' => null,
                    'debe' => (float) $recibo->monto_cobrado,
                    'haber' => 0,
                    'glosa' => 'Cobro ' . $recibo->numero_correlativo,
                ];
            }

            // Débito: cada retención a su cuenta destino.
            foreach ($recibo->retenciones as $ret) {
                $movimientos[] = [
                    'cuenta_id' => (int) $ret->cuenta_contable_id,
                    'centro_costo_id' => $this->admiteCc((int) $ret->cuenta_contable_id) ? $ccGeneral : null,
                    'auxiliar_id' => null,
                    'debe' => (float) $ret->monto,
                    'haber' => 0,
                    'glosa' => sprintf('Retención %s%s', $ret->tipo,
                        $ret->numero_certificado ? ' #' . $ret->numero_certificado : ''),
                ];
            }

            // Débito: NC aplicadas — la NC reduce el saldo del cliente
            // (es como un cobro). Se asienta como débito a "Notas de Crédito
            // a aplicar" o directamente cancelando deudor. Patrón: el NC ya
            // tiene asiento propio (de emisión), acá solo lo "aplicamos" al
            // deudor — esto se traduce en un débito a la cuenta del cliente
            // que equivale a la suma de NC, sin crear cuenta intermedia.
            // En el MVP lo omitimos del asiento: la NC ya impacta al cliente
            // al emitirse. El asiento del recibo sólo refleja cobro + ret.
            // Sin embargo, la línea de crédito al deudor se ajusta para
            // incluir las NC aplicadas (el deudor se cancela por el total
            // = monto_cobrado + retenciones + NC).

            // Crédito: cliente (Deudores) por total_factura (es lo que cancela el recibo).
            $totalCancelado = (float) $recibo->monto_cobrado
                + (float) $recibo->total_retenciones
                + (float) $recibo->total_nc_aplicadas;
            if ($totalCancelado > 0) {
                $movimientos[] = [
                    'cuenta_id' => $cuentaDeudoresId,
                    'centro_costo_id' => $ccGeneral,
                    'auxiliar_id' => $recibo->cliente_auxiliar_id,
                    'debe' => 0,
                    'haber' => round($totalCancelado, 2),
                    'glosa' => 'Cancela deudor ' . $recibo->numero_correlativo,
                ];
            }

            if (empty($movimientos)) {
                throw new DomainException('SIN_MOVIMIENTOS: el recibo no genera asiento (todos los importes en 0).');
            }

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioId,
                'fecha' => $recibo->fecha_emision->toDateString(),
                'glosa' => sprintf('Recibo %s · Cobro factura #%d',
                    $recibo->numero_correlativo, $recibo->factura_venta_id),
                'origen' => 'RECIBO',
                'origen_id' => $recibo->id,
                'origen_tabla' => 'erp_recibos',
                'usuario_id' => $usuario->id,
                'movimientos' => $movimientos,
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            // Persistir imputaciones NC en la tabla legacy (v1.15) para que el
            // tracking de saldo imputable de NC siga funcionando con el código
            // existente que mira erp_imputaciones_nc.
            foreach ($recibo->ncAplicadas as $nc) {
                DB::table('erp_imputaciones_nc')->insert([
                    'empresa_id' => $empresaId,
                    'nc_id' => $nc->nc_factura_id,
                    'factura_id' => $recibo->factura_venta_id,
                    'importe' => $nc->monto_aplicado,
                    'fecha_imputacion' => $recibo->fecha_emision->toDateString(),
                    'imputado_por' => $usuario->id,
                    'imputado_at' => now(),
                    'observaciones' => sprintf('Aplicación auto via Recibo %s', $recibo->numero_correlativo),
                ]);
            }

            // Actualizar saldo + estado_cobro de la factura.
            $nuevoEstado = $recibo->saldo_factura_post <= 0.01
                ? 'COBRADA'
                : 'COBRO_PARCIAL';
            DB::table('erp_facturas_venta')
                ->where('id', $recibo->factura_venta_id)
                ->update(['estado' => $nuevoEstado, 'updated_at' => now()]);

            $recibo->update([
                'estado' => Recibo::ESTADO_EMITIDO,
                'asiento_id' => $asiento->id,
                'emitido_at' => now(),
            ]);

            $this->audit->logEvento(
                accion: 'RECIBO_EMITIDO',
                modulo: 'tesoreria',
                descripcion: sprintf('Recibo %s emitido (asiento #%d, total cancelado $%.2f)',
                    $recibo->numero_correlativo, $asiento->id, $totalCancelado),
                empresaId: $empresaId,
            );

            return $asiento;
        });
    }

    /**
     * RN-31-12 — Anula un recibo emitido. Genera asiento reversa, libera
     * saldos, des-imputa NC.
     */
    public function anular(Recibo $recibo, string $motivo, User $usuario): void
    {
        if ($recibo->estado === Recibo::ESTADO_ANULADO) {
            throw new DomainException('YA_ANULADO');
        }
        if (! in_array($recibo->estado, [Recibo::ESTADO_EMITIDO, Recibo::ESTADO_CONCILIADO], true)) {
            throw new DomainException(sprintf(
                'ESTADO_INVALIDO: solo se anulan EMITIDO/CONCILIADO (actual: %s)', $recibo->estado,
            ));
        }
        if (trim($motivo) === '') {
            throw new DomainException('MOTIVO_REQUERIDO');
        }

        DB::transaction(function () use ($recibo, $motivo, $usuario) {
            // Generar asiento reversa si hay asiento original.
            if ($recibo->asiento_id) {
                $original = Asiento::with('movimientos')->findOrFail($recibo->asiento_id);
                $reversa = $this->asientoService->crearBorrador([
                    'empresa_id' => $recibo->empresa_id,
                    'diario_id' => $original->diario_id,
                    'fecha' => today()->toDateString(),
                    'glosa' => sprintf('Reversa recibo %s — %s', $recibo->numero_correlativo, $motivo),
                    'origen' => 'RECIBO_ANULADO',
                    'origen_id' => $recibo->id,
                    'origen_tabla' => 'erp_recibos',
                    'usuario_id' => $usuario->id,
                    'movimientos' => $original->movimientos->map(fn ($m) => [
                        'cuenta_id' => $m->cuenta_id,
                        'centro_costo_id' => $m->centro_costo_id,
                        'auxiliar_id' => $m->auxiliar_id,
                        'debe' => (float) $m->haber, // intercambiados
                        'haber' => (float) $m->debe,
                        'glosa' => 'Reversa: ' . $m->glosa,
                    ])->all(),
                ]);
                $this->asientoService->contabilizar($reversa);
            }

            // Des-imputar NC (eliminar las filas de imputaciones legacy).
            foreach ($recibo->ncAplicadas as $nc) {
                DB::table('erp_imputaciones_nc')
                    ->where('empresa_id', $recibo->empresa_id)
                    ->where('nc_id', $nc->nc_factura_id)
                    ->where('factura_id', $recibo->factura_venta_id)
                    ->where('importe', $nc->monto_aplicado)
                    ->limit(1)
                    ->delete();
            }

            // Liberar saldo de la factura: cambiar estado de COBRADA/COBRO_PARCIAL.
            // En MVP: si era COBRADA, vuelve a EMITIDA. Si COBRO_PARCIAL, queda
            // EMITIDA (asumiendo no hay otros recibos parciales).
            DB::table('erp_facturas_venta')
                ->where('id', $recibo->factura_venta_id)
                ->update(['estado' => 'EMITIDA', 'updated_at' => now()]);

            $recibo->update([
                'estado' => Recibo::ESTADO_ANULADO,
                'anulado_at' => now(),
                'anulado_por_user_id' => $usuario->id,
                'anulado_motivo' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'RECIBO_ANULADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Recibo %s anulado (motivo: %s)', $recibo->numero_correlativo, $motivo),
                empresaId: $recibo->empresa_id,
            );
        });
    }

    /**
     * Auto-imputación FIFO de NC libres del cliente sobre una factura WSFE.
     * Devuelve el array de NC aplicadas (formato esperado por crear()).
     */
    public function autoImputarNcFifo(FacturaVenta $factura, int $empresaId = 1): array
    {
        $saldoFactura = $this->saldoFactura($factura->id, $empresaId);
        if ($saldoFactura <= 0.01) return [];

        $ncs = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $factura->auxiliar_id)
            ->where('tc.clase', 'NOTA_CREDITO')
            ->whereNull('f.deleted_at')
            ->orderBy('f.fecha_emision')
            ->orderBy('f.id')
            ->get(['f.id', 'f.imp_total']);

        $aplicadas = [];
        $restante = $saldoFactura;
        foreach ($ncs as $nc) {
            $saldoNc = $this->saldoImputableNc((int) $nc->id, $empresaId);
            if ($saldoNc <= 0.01) continue;
            $aplicar = min($saldoNc, $restante);
            if ($aplicar <= 0.01) break;
            $aplicadas[] = [
                'nc_factura_id' => (int) $nc->id,
                'monto_aplicado' => round($aplicar, 2),
                'automatica' => true,
            ];
            $restante -= $aplicar;
            if ($restante <= 0.01) break;
        }
        return $aplicadas;
    }

    /**
     * Saldo imputable de una NC = imp_total - SUM(imputaciones existentes).
     */
    public function saldoImputableNc(int $ncId, int $empresaId = 1): float
    {
        $nc = DB::table('erp_facturas_venta')
            ->where('id', $ncId)->where('empresa_id', $empresaId)
            ->first(['imp_total']);
        if (! $nc) return 0.0;
        $imputado = (float) DB::table('erp_imputaciones_nc')
            ->where('empresa_id', $empresaId)->where('nc_id', $ncId)->sum('importe');
        return round((float) $nc->imp_total - $imputado, 2);
    }

    /**
     * Saldo de una factura = imp_total - SUM(cobros) - SUM(NC imputadas) - SUM(retenciones recibidas).
     * MVP: aprox via cobros + NC imputadas. Las retenciones se modelan en recibos a partir de v1.31.
     */
    public function saldoFactura(int $facturaId, int $empresaId = 1): float
    {
        $factura = DB::table('erp_facturas_venta')
            ->where('id', $facturaId)->where('empresa_id', $empresaId)
            ->first(['imp_total']);
        if (! $factura) return 0.0;

        $cobrado = (float) DB::table('erp_cobro_items')
            ->where('factura_id', $facturaId)
            ->where('tipo_item', 'FACTURA_VENTA')
            ->sum('importe');
        $ncImputada = (float) DB::table('erp_imputaciones_nc')
            ->where('empresa_id', $empresaId)->where('factura_id', $facturaId)->sum('importe');
        $retEmitidos = (float) DB::table('erp_recibos as r')
            ->join('erp_recibos_retenciones as rr', 'rr.recibo_id', '=', 'r.id')
            ->where('r.factura_venta_id', $facturaId)
            ->where('r.estado', '!=', Recibo::ESTADO_ANULADO)
            ->sum('rr.monto');
        $cobradoRecibos = (float) DB::table('erp_recibos')
            ->where('factura_venta_id', $facturaId)
            ->where('estado', '!=', Recibo::ESTADO_ANULADO)
            ->sum('monto_cobrado');

        // El monto_cobrado de los recibos también pasa por erp_cobros? En MVP,
        // los recibos NO generan cobros sueltos — son la unica fuente. Para
        // evitar doble conteo, descartamos cobros si hay recibo.
        $tieneRecibo = DB::table('erp_recibos')
            ->where('factura_venta_id', $facturaId)
            ->where('estado', '!=', Recibo::ESTADO_ANULADO)
            ->exists();
        $cobroEfectivo = $tieneRecibo ? $cobradoRecibos : $cobrado;

        return round((float) $factura->imp_total - $cobroEfectivo - $ncImputada - $retEmitidos, 2);
    }

    private function siguienteNumero(int $empresaId, ?string $fecha = null): string
    {
        $anio = $fecha ? substr($fecha, 0, 4) : date('Y');
        $maxNum = DB::table('erp_recibos')
            ->where('empresa_id', $empresaId)
            ->where('numero_correlativo', 'like', "R-{$anio}-%")
            ->orderByDesc('id')
            ->limit(1)
            ->value('numero_correlativo');
        $next = 1;
        if ($maxNum && preg_match('/-(\d+)$/', $maxNum, $m)) {
            $next = (int) $m[1] + 1;
        }
        return sprintf('R-%s-%08d', $anio, $next);
    }

    private function admiteCc(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')
            ->where('id', $cuentaId)->value('admite_centro_costo');
    }
}
