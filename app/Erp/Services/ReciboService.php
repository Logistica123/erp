<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\Recibo;
use App\Erp\Models\Tesoreria\ReciboComprobanteImputado;
use App\Erp\Models\Tesoreria\ReciboNcAplicada;
use App\Erp\Models\Tesoreria\ReciboRetencion;
use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Services\Integracion\DistriAppBridge;
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
        private readonly DistriAppBridge $distri, // v1.32 — sync numeración cross-platform.
        private readonly \App\Erp\Services\Tesoreria\ChequeRecibidoService $cheques,
    ) {}

    public const PV_DEFAULT = '00001';

    /**
     * v1.32 — Crea un recibo en estado BORRADOR con múltiples comprobantes
     * imputados, retenciones simples (IVA/IIBB/Gan) y detalle del cobro.
     *
     * Back-compat: si se pasa `factura_venta_id` en vez de `comprobantes_imputados`,
     * se convierte a un único comprobante imputado con monto=total_factura.
     *
     * @param  array{
     *   cliente_auxiliar_id:int,
     *   fecha_emision?:string,
     *   fecha_cobro?:string,
     *   detalle_cobro?:?string,
     *   comprobantes_imputados?:list<array{factura_venta_id:int, monto_imputado:float}>,
     *   factura_venta_id?:int,
     *   nc_aplicadas?:list<array{nc_factura_id:int, monto_aplicado:float, automatica?:bool}>,
     *   retenciones?:list<array{tipo:string, jurisdiccion_codigo?:?string, numero_certificado?:?string,
     *                            alicuota?:?float, base_imponible?:?float, monto:float, cuenta_contable_id:int}>,
     *   retencion_iva_total?:float,
     *   retencion_iibb_total?:float,
     *   retencion_ganancias_total?:float,
     *   monto_cobrado?:float,
     *   medio_cobro_id?:?int,
     *   observaciones?:?string,
     *   auto_imputar_nc?:bool,
     * }  $data
     */
    public function crear(array $data, User $usuario, int $empresaId = 1): Recibo
    {
        return DB::transaction(function () use ($data, $usuario, $empresaId) {
            // Normalizar comprobantes: si vino factura_venta_id (v1.31), wrap.
            $comprobantes = $data['comprobantes_imputados'] ?? [];
            if (empty($comprobantes) && ! empty($data['factura_venta_id'])) {
                $fv = FacturaVenta::where('empresa_id', $empresaId)
                    ->findOrFail($data['factura_venta_id']);
                $comprobantes = [[
                    'factura_venta_id' => (int) $fv->id,
                    'monto_imputado' => (float) $fv->imp_total,
                ]];
            }
            if (empty($comprobantes)) {
                throw new DomainException('SIN_COMPROBANTES: el recibo debe tener al menos un comprobante imputado.');
            }

            $clienteId = (int) $data['cliente_auxiliar_id'];
            // v1.34 — auxiliares hermanos (mismo CUIT) son el mismo cliente real.
            // El import del Libro IVA crea CLI-* y el sync DistriApp DA-CLI-* para
            // el mismo CUIT; aceptamos facturas de cualquiera de ellos.
            $hermanos = $this->auxiliaresHermanos($clienteId, $empresaId);

            // v1.32 — Si el medio es Compensación (codigo COMP_CC), saltamos
            // las validaciones de saldo: el recibo es contablemente neutro
            // (D=H sobre Deudores por Ventas) y puede tocar facturas/NC ya
            // imputadas para dejar registro del evento.
            $esCompCrear = $this->esCompensacion(
                isset($data['medio_cobro_id']) ? (int) $data['medio_cobro_id'] : null
            );

            // Cargar y validar facturas (con lock).
            $facturasCache = [];
            $totalImputado = 0.0;
            foreach ($comprobantes as $i => $c) {
                $fvId = (int) $c['factura_venta_id'];
                $monto = (float) $c['monto_imputado'];
                if ($monto <= 0) {
                    throw new DomainException("MONTO_IMPUTADO_INVALIDO: linea {$i} con monto <= 0");
                }
                $fv = FacturaVenta::where('empresa_id', $empresaId)
                    ->where('id', $fvId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! in_array((int) $fv->auxiliar_id, $hermanos, true)) {
                    throw new DomainException(sprintf(
                        'CLIENTE_INCONSISTENTE: factura #%d pertenece a auxiliar #%d, no al cliente #%d (ni a sus hermanos por CUIT)',
                        $fv->id, $fv->auxiliar_id, $clienteId,
                    ));
                }
                $saldo = $this->saldoFactura($fv->id, $empresaId);
                // En compensación se permiten facturas con saldo 0 (recibo neutro D=H).
                if (! $esCompCrear && $monto > $saldo + 0.01) {
                    throw new DomainException(sprintf(
                        'FACTURA_SOBRE_IMPUTADA: factura #%d tiene saldo $%.2f, se intenta imputar $%.2f',
                        $fv->id, $saldo, $monto,
                    ));
                }
                $facturasCache[$fvId] = $fv;
                $totalImputado += $monto;
            }

            // NC + retenciones.
            $ncAplicadas = $data['nc_aplicadas'] ?? [];
            // Auto-imputación FIFO si vino una sola factura WSFE.
            if (empty($ncAplicadas) && ($data['auto_imputar_nc'] ?? false)
                && count($comprobantes) === 1) {
                $unicaFactura = array_values($facturasCache)[0];
                if (in_array($unicaFactura->origen, ['EMITIDA', 'WSFE_ERP'], true)) {
                    $ncAplicadas = $this->autoImputarNcFifo($unicaFactura, $empresaId);
                }
            }
            foreach ($ncAplicadas as $nc) {
                $saldo = $this->saldoImputableNc((int) $nc['nc_factura_id'], $empresaId);
                if (! $esCompCrear && (float) $nc['monto_aplicado'] > $saldo + 0.01) {
                    throw new DomainException(sprintf(
                        'NC_SOBRE_IMPUTADA: NC #%d tiene saldo imputable $%.2f pero se intenta aplicar $%.2f',
                        $nc['nc_factura_id'], $saldo, $nc['monto_aplicado'],
                    ));
                }
            }

            $retencionesDetalle = $data['retenciones'] ?? [];
            // v1.32 — Retenciones simples (sumatoria por tipo, sin detalle).
            $retIvaSimple = (float) ($data['retencion_iva_total'] ?? 0);
            $retIibbSimple = (float) ($data['retencion_iibb_total'] ?? 0);
            $retGanSimple = (float) ($data['retencion_ganancias_total'] ?? 0);
            $totalRetSimple = $retIvaSimple + $retIibbSimple + $retGanSimple;
            $totalRetDetalle = array_sum(array_map(fn ($r) => (float) $r['monto'], $retencionesDetalle));
            // Las dos formas son aditivas (puede haber un certificado de Ganancias
            // detallado + un total IIBB sin certificado). El operador elige cómo.
            $totalRet = $totalRetSimple + $totalRetDetalle;
            $totalNc = array_sum(array_map(fn ($n) => (float) $n['monto_aplicado'], $ncAplicadas));
            // "Otro" — medio/compensación especial: suma al cobro como una retención.
            $otroMonto = (float) ($data['otro_monto'] ?? 0);
            $otroObs = $data['otro_observacion'] ?? null;
            // Redondeo — ajuste de cobranza (puede ser negativo).
            $redondeo = round((float) ($data['redondeo_monto'] ?? 0), 2);

            // Cálculos.
            $montoCobrable = max(0.0, $totalImputado - $totalNc - $totalRet - $otroMonto - $redondeo);
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

            // Para back-compat con saldo_factura_post (v1.31), usamos el primer
            // comprobante. Es informativo — el saldo real de cada factura se
            // recalcula on-the-fly via saldoFactura().
            $primerComprobante = $comprobantes[0];
            $primerFactura = $facturasCache[(int) $primerComprobante['factura_venta_id']];
            $saldoPostPrimera = round(
                $this->saldoFactura($primerFactura->id, $empresaId) - (float) $primerComprobante['monto_imputado'],
                2,
            );

            // Crear el recibo en BORRADOR. punto_venta/numero quedan NULL hasta
            // emitir (la numeración se reserva ahí para evitar gaps por borradores
            // abandonados).
            $recibo = Recibo::create([
                'empresa_id' => $empresaId,
                // 'BORRADOR-'.uniqid('',true) generaba ~32 chars y la columna
                // es VARCHAR(20). 'B-' + 16 hex random = 18 chars, único.
                'numero_correlativo' => 'B-' . bin2hex(random_bytes(8)),
                'punto_venta' => null,
                'numero' => null,
                'fecha_emision' => today()->toDateString(),
                'fecha_cobro' => $this->resolverFechaCobro($data),
                'detalle_cobro' => $data['detalle_cobro'] ?? null,
                'cliente_auxiliar_id' => $clienteId,
                'factura_venta_id' => $primerFactura->id, // back-compat v1.31
                'total_factura' => round($totalImputado, 2),
                'total_nc_aplicadas' => round($totalNc, 2),
                'total_retenciones' => round($totalRet, 2),
                'retencion_iva_total' => round($retIvaSimple, 2),
                'retencion_iibb_total' => round($retIibbSimple, 2),
                'retencion_ganancias_total' => round($retGanSimple, 2),
                'otro_monto' => round($otroMonto, 2),
                'otro_observacion' => $otroObs,
                'redondeo_monto' => $redondeo,
                'monto_cobrable' => round($montoCobrable, 2),
                'monto_cobrado' => round($montoCobrado, 2),
                'saldo_factura_post' => max(0.0, $saldoPostPrimera),
                'medio_cobro_id' => $data['medio_cobro_id'] ?? null,
                'estado' => Recibo::ESTADO_BORRADOR,
                'observaciones' => $data['observaciones'] ?? null,
                'created_by_user_id' => $usuario->id,
                'created_at' => now(),
            ]);

            // Persistir comprobantes imputados con snapshot.
            foreach ($comprobantes as $c) {
                $fv = $facturasCache[(int) $c['factura_venta_id']];
                $pvNumero = (int) DB::table('erp_puntos_venta')->where('id', $fv->punto_venta_id)->value('numero');
                $numFactura = sprintf('%04d-%08d', $pvNumero, (int) $fv->numero);
                DB::table('erp_recibos_comprobantes_imputados')->insert([
                    'recibo_id' => $recibo->id,
                    'factura_venta_id' => $fv->id,
                    'monto_imputado' => round((float) $c['monto_imputado'], 2),
                    'total_factura' => (float) $fv->imp_total,
                    'fecha_factura' => $fv->fecha_emision,
                    'numero_factura_snapshot' => $numFactura,
                    'created_at' => now(),
                ]);
            }

            foreach ($ncAplicadas as $nc) {
                ReciboNcAplicada::create([
                    'recibo_id' => $recibo->id,
                    'nc_factura_id' => (int) $nc['nc_factura_id'],
                    'monto_aplicado' => round((float) $nc['monto_aplicado'], 2),
                    'automatica' => (bool) ($nc['automatica'] ?? false),
                    'created_at' => now(),
                ]);
            }
            foreach ($retencionesDetalle as $r) {
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
                descripcion: sprintf('Recibo borrador creado (cliente #%d, %d comprobantes, imputado $%.2f, NC $%.2f, ret $%.2f, cobrado $%.2f)',
                    $clienteId, count($comprobantes), $totalImputado, $totalNc, $totalRet, $montoCobrado),
                empresaId: $empresaId,
            );

            return $recibo->fresh();
        });
    }

    /**
     * v1.32 — Actualiza un recibo en estado BORRADOR. Reemplaza por completo
     * las imputaciones, NC aplicadas y retenciones (delete + insert) y
     * recalcula los totales. Soporta editar/quitar/agregar comprobantes en un
     * borrador antes de emitirlo.
     *
     * Acepta el MISMO shape de $data que crear().
     */
    public function actualizar(Recibo $recibo, array $data, User $usuario, int $empresaId = 1): Recibo
    {
        if ($recibo->estado !== Recibo::ESTADO_BORRADOR) {
            throw new DomainException(sprintf(
                'ESTADO_INVALIDO: solo se editan recibos en BORRADOR (actual: %s)', $recibo->estado,
            ));
        }

        return DB::transaction(function () use ($recibo, $data, $usuario, $empresaId) {
            // Relock + recheck estado (defensa por carrera con emitir/anular).
            $recibo = Recibo::where('empresa_id', $empresaId)
                ->lockForUpdate()->findOrFail($recibo->id);
            if ($recibo->estado !== Recibo::ESTADO_BORRADOR) {
                throw new DomainException('ESTADO_INVALIDO: el recibo dejó de ser BORRADOR durante la edición.');
            }

            // 1) Validar PRIMERO (sin borrar nada). Si falla, el borrador queda
            //    intacto. saldoFactura recibe excludeReciboId para que NO sume
            //    la contribución actual de este recibo (sino daría saldo
            //    negativo por doble conteo).
            $comprobantes = $data['comprobantes_imputados'] ?? [];
            if (empty($comprobantes)) {
                throw new DomainException('SIN_COMPROBANTES: el recibo debe tener al menos un comprobante imputado.');
            }
            $clienteId = (int) $data['cliente_auxiliar_id'];
            $hermanos = $this->auxiliaresHermanos($clienteId, $empresaId);
            // v1.32 — Compensación: skipea validación de saldo (D=H neutro).
            $esCompAct = $this->esCompensacion(
                isset($data['medio_cobro_id']) ? (int) $data['medio_cobro_id'] : null
            );

            $facturasCache = [];
            $totalImputado = 0.0;
            foreach ($comprobantes as $i => $c) {
                $fvId = (int) $c['factura_venta_id'];
                $monto = (float) $c['monto_imputado'];
                if ($monto <= 0) {
                    throw new DomainException("MONTO_IMPUTADO_INVALIDO: linea {$i} con monto <= 0");
                }
                $fv = FacturaVenta::where('empresa_id', $empresaId)
                    ->where('id', $fvId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! in_array((int) $fv->auxiliar_id, $hermanos, true)) {
                    throw new DomainException(sprintf(
                        'CLIENTE_INCONSISTENTE: factura #%d pertenece a auxiliar #%d, no al cliente #%d (ni hermanos por CUIT)',
                        $fv->id, $fv->auxiliar_id, $clienteId,
                    ));
                }
                $saldo = $this->saldoFactura($fv->id, $empresaId, $recibo->id);
                if (! $esCompAct && $monto > $saldo + 0.01) {
                    throw new DomainException(sprintf(
                        'FACTURA_SOBRE_IMPUTADA: factura #%d tiene saldo $%.2f, se intenta imputar $%.2f',
                        $fv->id, $saldo, $monto,
                    ));
                }
                $facturasCache[$fvId] = $fv;
                $totalImputado += $monto;
            }

            $ncAplicadas = $data['nc_aplicadas'] ?? [];
            foreach ($ncAplicadas as $nc) {
                $saldoNc = $this->saldoImputableNc((int) $nc['nc_factura_id'], $empresaId);
                if (! $esCompAct && (float) $nc['monto_aplicado'] > $saldoNc + 0.01) {
                    throw new DomainException(sprintf(
                        'NC_SOBRE_IMPUTADA: NC #%d saldo imputable $%.2f, se aplica $%.2f',
                        $nc['nc_factura_id'], $saldoNc, $nc['monto_aplicado'],
                    ));
                }
            }

            // 2) Validación pasó: borrar imputaciones/NC/retenciones existentes
            //    y re-insertar en el mismo loop transaccional.
            DB::table('erp_recibos_comprobantes_imputados')->where('recibo_id', $recibo->id)->delete();
            DB::table('erp_recibos_nc_aplicadas')->where('recibo_id', $recibo->id)->delete();
            DB::table('erp_recibos_retenciones')->where('recibo_id', $recibo->id)->delete();

            $retencionesDetalle = $data['retenciones'] ?? [];
            $retIvaSimple = (float) ($data['retencion_iva_total'] ?? 0);
            $retIibbSimple = (float) ($data['retencion_iibb_total'] ?? 0);
            $retGanSimple = (float) ($data['retencion_ganancias_total'] ?? 0);
            $totalRetSimple = $retIvaSimple + $retIibbSimple + $retGanSimple;
            $totalRetDetalle = array_sum(array_map(fn ($r) => (float) $r['monto'], $retencionesDetalle));
            $totalRet = $totalRetSimple + $totalRetDetalle;
            $totalNc = array_sum(array_map(fn ($n) => (float) $n['monto_aplicado'], $ncAplicadas));
            $otroMonto = (float) ($data['otro_monto'] ?? 0);
            $otroObs = $data['otro_observacion'] ?? null;
            $redondeo = round((float) ($data['redondeo_monto'] ?? 0), 2);

            $montoCobrable = max(0.0, $totalImputado - $totalNc - $totalRet - $otroMonto - $redondeo);
            $montoCobrado = isset($data['monto_cobrado']) ? (float) $data['monto_cobrado'] : $montoCobrable;
            if ($montoCobrado > $montoCobrable + 0.01) {
                throw new DomainException(sprintf(
                    'MONTO_COBRADO_EXCEDE_COBRABLE: $%.2f > $%.2f', $montoCobrado, $montoCobrable,
                ));
            }
            if ($montoCobrado > 0 && empty($data['medio_cobro_id'])) {
                throw new DomainException('MEDIO_COBRO_REQUERIDO: el monto cobrado > 0 requiere medio_cobro_id.');
            }

            $primerComprobante = $comprobantes[0];
            $primerFactura = $facturasCache[(int) $primerComprobante['factura_venta_id']];
            $saldoPostPrimera = round(
                $this->saldoFactura($primerFactura->id, $empresaId) - (float) $primerComprobante['monto_imputado'],
                2,
            );

            // 3) UPDATE del recibo con totales nuevos. Conservamos
            //    numero_correlativo (B-xxx), punto_venta=NULL, numero=NULL,
            //    estado=BORRADOR — solo cambian los campos editables.
            $recibo->update([
                'fecha_emision' => today()->toDateString(),
                'fecha_cobro' => $this->resolverFechaCobro($data),
                'detalle_cobro' => $data['detalle_cobro'] ?? null,
                'cliente_auxiliar_id' => $clienteId,
                'factura_venta_id' => $primerFactura->id, // back-compat
                'total_factura' => round($totalImputado, 2),
                'total_nc_aplicadas' => round($totalNc, 2),
                'total_retenciones' => round($totalRet, 2),
                'retencion_iva_total' => round($retIvaSimple, 2),
                'retencion_iibb_total' => round($retIibbSimple, 2),
                'retencion_ganancias_total' => round($retGanSimple, 2),
                'otro_monto' => round($otroMonto, 2),
                'otro_observacion' => $otroObs,
                'redondeo_monto' => $redondeo,
                'monto_cobrable' => round($montoCobrable, 2),
                'monto_cobrado' => round($montoCobrado, 2),
                'saldo_factura_post' => max(0.0, $saldoPostPrimera),
                'medio_cobro_id' => $data['medio_cobro_id'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            // 4) Re-insertar comprobantes / NC / retenciones (mismo código de crear()).
            foreach ($comprobantes as $c) {
                $fv = $facturasCache[(int) $c['factura_venta_id']];
                $pvNumero = (int) DB::table('erp_puntos_venta')->where('id', $fv->punto_venta_id)->value('numero');
                $numFactura = sprintf('%04d-%08d', $pvNumero, (int) $fv->numero);
                DB::table('erp_recibos_comprobantes_imputados')->insert([
                    'recibo_id' => $recibo->id,
                    'factura_venta_id' => $fv->id,
                    'monto_imputado' => round((float) $c['monto_imputado'], 2),
                    'total_factura' => (float) $fv->imp_total,
                    'fecha_factura' => $fv->fecha_emision,
                    'numero_factura_snapshot' => $numFactura,
                    'created_at' => now(),
                ]);
            }
            foreach ($ncAplicadas as $nc) {
                ReciboNcAplicada::create([
                    'recibo_id' => $recibo->id,
                    'nc_factura_id' => (int) $nc['nc_factura_id'],
                    'monto_aplicado' => round((float) $nc['monto_aplicado'], 2),
                    'automatica' => (bool) ($nc['automatica'] ?? false),
                    'created_at' => now(),
                ]);
            }
            foreach ($retencionesDetalle as $r) {
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
                accion: 'RECIBO_ACTUALIZADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Borrador #%d actualizado (cliente #%d, %d comprobantes, imputado $%.2f, NC $%.2f, ret $%.2f, cobrado $%.2f)',
                    $recibo->id, $clienteId, count($comprobantes), $totalImputado, $totalNc, $totalRet, $montoCobrado),
                empresaId: $empresaId,
            );

            return $recibo->fresh();
        });
    }

    /**
     * RN-31-2 — Emite el recibo (BORRADOR → EMITIDO + genera asiento).
     */
    /**
     * @param  ?array<int,array<string,mixed>>  $chequesData  cheques físicos
     *     recibidos cuando el medio_cobro es CHEQUES_CARTERA. Puede ser uno o
     *     varios (ej. Urbano paga con muchos cheques chicos). Cada elemento:
     *     numero_cheque, banco_emisor, cuit_librador?, librador_nombre?,
     *     fecha_emision, fecha_pago, importe. Por back-compat también se acepta
     *     un único cheque como array asociativo (se normaliza a lista).
     */
    public function emitir(Recibo $recibo, User $usuario, ?array $chequesData = null): Asiento
    {
        if ($recibo->estado !== Recibo::ESTADO_BORRADOR) {
            throw new DomainException(sprintf(
                'ESTADO_INVALIDO: solo se emiten recibos en BORRADOR (actual: %s)', $recibo->estado,
            ));
        }

        // Normalizar: aceptar un cheque único (assoc) o una lista de cheques.
        if ($chequesData && array_key_exists('numero_cheque', $chequesData)) {
            $chequesData = [$chequesData];
        }

        // Si el medio de cobro es CHEQUES_CARTERA, exigir al menos un cheque.
        $esCheque = $this->medioEsChequesCartera((int) $recibo->medio_cobro_id);
        if ($esCheque && empty($chequesData)) {
            throw new DomainException(
                'CHEQUE_DATOS_REQUERIDOS: el medio de cobro "Cheques en Cartera" requiere '
                . 'al menos un cheque (número, banco, fechas, importe).'
            );
        }

        return DB::transaction(function () use ($recibo, $usuario, $chequesData, $esCheque) {
            $empresaId = $recibo->empresa_id;

            // v1.32 — Reservar número PV-NRO sincronizado con DistriApp.
            // El BORRADOR no tiene número asignado todavía (evita gaps).
            if (! $recibo->punto_venta || ! $recibo->numero) {
                $pv = self::PV_DEFAULT;
                $numero = $this->siguienteNumeroSincronizado($pv);
                $recibo->update([
                    'punto_venta' => $pv,
                    'numero' => $numero,
                    'numero_correlativo' => "{$pv}-{$numero}", // legacy field
                ]);
                $recibo->refresh();
            }

            // v1.32 — Snapshot inmutable de empresa al EMITIR.
            $empresa = DB::table('erp_empresas')->where('id', $empresaId)->first();
            $cliente = DB::table('erp_auxiliares')->where('id', $recibo->cliente_auxiliar_id)->first();
            // Datos extendidos del cliente desde DistriApp si tiene id_ref.
            $clienteDistri = null;
            if ($cliente && $cliente->tabla_ref === 'basepersonal.clientes' && $cliente->id_ref) {
                $clienteDistri = $this->distri->datosCliente((int) $cliente->id_ref);
            }

            $direccionParts = $empresa->domicilio_fiscal ? array_map('trim', explode(',', $empresa->domicilio_fiscal, 2)) : ['', ''];
            $recibo->update([
                'snapshot_empresa_razon_social' => $empresa->razon_social,
                'snapshot_empresa_cuit' => $empresa->cuit,
                'snapshot_empresa_direccion_1' => $direccionParts[0] ?? '',
                'snapshot_empresa_direccion_2' => $direccionParts[1] ?? '',
                'snapshot_empresa_condicion_iva' => $this->expandirCondicionIva($empresa->condicion_iva),
                'snapshot_empresa_inicio_actividad' => $empresa->fecha_inicio_actividades,
                'snapshot_cliente_razon_social' => $clienteDistri->nombre ?? $cliente->nombre,
                'snapshot_cliente_cuit' => $cliente->cuit ?? ($clienteDistri->cuit ?? null),
                'snapshot_cliente_direccion_1' => $clienteDistri->direccion_1 ?? '',
                'snapshot_cliente_direccion_2' => $clienteDistri->direccion_2 ?? '',
                'snapshot_cliente_condicion_iva' => $clienteDistri->condicion_iva ?? '',
            ]);

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

            // v1.32 — Si el medio es COMPENSACIÓN, generamos un asiento espejo
            // (D=H sobre Deudores por Ventas del mismo cliente) por el total
            // imputado. Contablemente neutro: registra el evento sin tocar
            // bancos/cajas ni otras cuentas. Skipea la lógica normal de débitos.
            //
            // Detección automática: compensación pura cuando no hay efectivo
            // (monto_cobrado=0) ni retenciones, y las NC aplicadas cancelan el
            // total — el frontend no siempre setea medio_cobro_id porque visualmente
            // no hay "medio". Sin esto el asiento sólo tendría 1 línea (crédito
            // Deudores) y AsientoService rebota ASIENTO_MINIMO.
            $esCompensacionPura = ((float) $recibo->monto_cobrado <= 0.01)
                && ((float) $recibo->total_retenciones <= 0.01)
                && ((float) $recibo->total_nc_aplicadas > 0.01)
                && ((float) $recibo->total_factura > 0.01);

            if ($this->esCompensacion((int) $recibo->medio_cobro_id) || $esCompensacionPura) {
                $totalCompensado = round((float) $recibo->total_factura, 2);
                if ($totalCompensado > 0) {
                    $movs = [
                        [
                            'cuenta_id' => $cuentaDeudoresId,
                            'centro_costo_id' => $ccGeneral,
                            'auxiliar_id' => $recibo->cliente_auxiliar_id,
                            'debe' => $totalCompensado,
                            'haber' => 0,
                            'glosa' => 'Compensación NC vs facturas — ' . $recibo->numero_correlativo,
                        ],
                        [
                            'cuenta_id' => $cuentaDeudoresId,
                            'centro_costo_id' => $ccGeneral,
                            'auxiliar_id' => $recibo->cliente_auxiliar_id,
                            'debe' => 0,
                            'haber' => $totalCompensado,
                            'glosa' => 'Compensación NC vs facturas — ' . $recibo->numero_correlativo,
                        ],
                    ];
                    $movimientos = $movs;
                }
                // Saltea toda la lógica normal de medio/retenciones/NC.
                goto compensacionDone;
            }

            // Débito: medio_cobro por monto_cobrado.
            if ((float) $recibo->monto_cobrado > 0) {
                $cuentaMedioId = DB::table('erp_cuentas_bancarias')
                    ->where('id', $recibo->medio_cobro_id)
                    ->value('cuenta_contable_id');
                if (! $cuentaMedioId) throw new RuntimeException('Medio de cobro sin cuenta contable.');
                // Si la cuenta admite/requiere auxiliar Cliente (ej. 1.1.4.04
                // "Valores al Cobro - Cheques Recibidos"), pasamos el cliente
                // del recibo. AsientoService rebota AUXILIAR_REQUERIDO si la
                // cuenta lo pide y no se pasa.
                $cuentaMedioMeta = DB::table('erp_cuentas_contables')
                    ->where('id', $cuentaMedioId)
                    ->first(['admite_auxiliar', 'tipo_auxiliar']);
                $auxMedio = ($cuentaMedioMeta && (int) $cuentaMedioMeta->admite_auxiliar === 1
                    && $cuentaMedioMeta->tipo_auxiliar === 'Cliente')
                    ? (int) $recibo->cliente_auxiliar_id
                    : null;
                $movimientos[] = [
                    'cuenta_id' => $cuentaMedioId,
                    'centro_costo_id' => $this->admiteCc((int) $cuentaMedioId) ? $ccGeneral : null,
                    'auxiliar_id' => $auxMedio,
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

            // v1.32 — Débito por retenciones SIMPLES (totales por tipo en
            // erp_recibos.retencion_*_total). Estas no se persisten como filas
            // detalladas en erp_recibos_retenciones, así que el foreach de
            // arriba no las cubre y el asiento queda desbalanceado por la
            // diferencia exacta del total simple no acreditado. Mapeo:
            //   IVA  -> 1.1.6.05 Retenciones IVA Sufridas
            //   IIBB -> 1.1.6.10 Retenciones IIBB Sufridas
            //   GAN  -> 1.1.6.06 Retenciones Ganancias Sufridas
            // Las simples y detalladas son aditivas (igual que en crear()).
            $cuentaPorCodigoRet = function (string $codigo) use ($empresaId): int {
                $id = (int) DB::table('erp_cuentas_contables')
                    ->where('empresa_id', $empresaId)->where('codigo', $codigo)
                    ->value('id');
                if (! $id) {
                    throw new RuntimeException("Cuenta {$codigo} no existe en el plan de cuentas.");
                }
                return $id;
            };
            $retSimples = [
                ['monto' => (float) $recibo->retencion_iva_total, 'codigo' => '1.1.6.05', 'tipo' => 'IVA'],
                ['monto' => (float) $recibo->retencion_iibb_total, 'codigo' => '1.1.6.10', 'tipo' => 'IIBB'],
                ['monto' => (float) $recibo->retencion_ganancias_total, 'codigo' => '1.1.6.06', 'tipo' => 'GANANCIAS'],
            ];
            foreach ($retSimples as $r) {
                if ($r['monto'] <= 0) continue;
                $cuentaId = $cuentaPorCodigoRet($r['codigo']);
                $movimientos[] = [
                    'cuenta_id' => $cuentaId,
                    'centro_costo_id' => $this->admiteCc($cuentaId) ? $ccGeneral : null,
                    'auxiliar_id' => null,
                    'debe' => round($r['monto'], 2),
                    'haber' => 0,
                    'glosa' => sprintf('Retención %s — recibo %s', $r['tipo'], $recibo->numero_correlativo),
                ];
            }

            // Débito: "Otro" (medio/compensación especial). Suma al cobro como
            // una retención, pero su contrapartida va a la cuenta puente 1.1.6.99
            // "Pendientes de Identificar" para reclasificarse luego
            // (/erp/contabilidad/reclasificar-pendientes). La observación queda
            // como glosa de la línea.
            $otroMonto = round((float) $recibo->otro_monto, 2);
            if ($otroMonto > 0) {
                $cuentaOtroId = $cuentaPorCodigoRet('1.1.6.99');
                $movimientos[] = [
                    'cuenta_id' => $cuentaOtroId,
                    'centro_costo_id' => $this->admiteCc($cuentaOtroId) ? $ccGeneral : null,
                    'auxiliar_id' => null,
                    'debe' => $otroMonto,
                    'haber' => 0,
                    'glosa' => 'Otro (medio especial)'
                        .($recibo->otro_observacion ? ' — '.$recibo->otro_observacion : '')
                        .' — recibo '.$recibo->numero_correlativo,
                ];
            }

            // Redondeo: ajuste contra la cuenta de resultado 5.6.06 "Redondeos".
            // Positivo (pérdida) → débito; negativo (ganancia) → crédito. Suma al
            // crédito a Deudores como el resto de los componentes del cobro.
            $redondeo = round((float) $recibo->redondeo_monto, 2);
            if (abs($redondeo) > 0.001) {
                $cuentaRedId = $cuentaPorCodigoRet('5.6.06');
                $movimientos[] = [
                    'cuenta_id' => $cuentaRedId,
                    'centro_costo_id' => $this->admiteCc($cuentaRedId) ? $ccGeneral : null,
                    'auxiliar_id' => null,
                    'debe' => $redondeo > 0 ? $redondeo : 0,
                    'haber' => $redondeo < 0 ? -$redondeo : 0,
                    'glosa' => 'Redondeo cobranza — recibo '.$recibo->numero_correlativo,
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

            // Crédito: cliente (Deudores). Cancela SOLO la parte cubierta por
            // cobro + retenciones. Las NC aplicadas NO se acreditan acá: la NC
            // ya acreditó Deudores en su propio asiento de emisión
            // (ContabilizadorFacturas: Debe 4.1.2.01 NC emitidas / Haber 1.1.4.01
            // Deudores). Incluirlas de nuevo duplicaba el crédito y desbalanceaba
            // el asiento por el total exacto de NC (no había débito de
            // contrapartida). El recibo sólo registra la APLICACIÓN de la NC
            // (saldo imputable, vía erp_imputaciones_nc más abajo), sin volver a
            // asentarla. monto_cobrado + retenciones = total_factura − NC, que es
            // justo el saldo de deudor que queda por cancelar.
            $totalCancelado = (float) $recibo->monto_cobrado
                + (float) $recibo->total_retenciones
                + $otroMonto
                + $redondeo;
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

            compensacionDone:
            if (empty($movimientos)) {
                throw new DomainException('SIN_MOVIMIENTOS: el recibo no genera asiento (todos los importes en 0).');
            }

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioId,
                // El asiento usa la FECHA DE COBRO (fecha económica del cobro),
                // no la de emisión del recibo (hoy).
                'fecha' => ($recibo->fecha_cobro ?? $recibo->fecha_emision)->toDateString(),
                'glosa' => sprintf('Recibo %s · Cobro factura #%d',
                    $recibo->numero_correlativo, $recibo->factura_venta_id),
                // erp_asientos.origen es un ENUM que NO incluye 'RECIBO'. Usamos
                // 'COBRO' (el origen semánticamente correcto del asiento que
                // genera un recibo). origen_tabla='erp_recibos' identifica la
                // fuente puntual.
                'origen' => 'COBRO',
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
                    'fecha_imputacion' => ($recibo->fecha_cobro ?? $recibo->fecha_emision)->toDateString(),
                    'imputado_por' => $usuario->id,
                    'imputado_at' => now(),
                    'observaciones' => sprintf('Aplicación auto via Recibo %s', $recibo->numero_correlativo),
                ]);
            }

            // v1.32 — Actualizar estado de TODAS las facturas imputadas (multi-comprobante).
            $imputados = DB::table('erp_recibos_comprobantes_imputados')
                ->where('recibo_id', $recibo->id)->get();
            foreach ($imputados as $imp) {
                $saldoActual = $this->saldoFactura((int) $imp->factura_venta_id, $empresaId);
                $nuevoEstado = $saldoActual <= 0.01 ? 'COBRADA' : 'COBRO_PARCIAL';
                DB::table('erp_facturas_venta')
                    ->where('id', $imp->factura_venta_id)
                    ->update(['estado' => $nuevoEstado, 'updated_at' => now()]);
            }

            $recibo->update([
                'estado' => Recibo::ESTADO_EMITIDO,
                'fecha_emision' => today()->toDateString(), // emisión = hoy (momento real de emisión)
                'asiento_id' => $asiento->id,
                'emitido_at' => now(),
            ]);

            // Si el medio es CHEQUES_CARTERA, crear una fila en
            // erp_cheques_recibidos por cada cheque físico. La suma de los
            // cheques debe igualar el monto cobrado (el asiento debita la cuenta
            // "Valores al Cobro" por ese total en una sola línea).
            if ($esCheque && $chequesData) {
                $sumaCheques = round(array_sum(array_map(
                    fn ($c) => (float) ($c['importe'] ?? 0), $chequesData,
                )), 2);
                $cobrado = round((float) $recibo->monto_cobrado, 2);
                if (abs($sumaCheques - $cobrado) > 0.01) {
                    throw new DomainException(sprintf(
                        'CHEQUES_DESCUADRAN: la suma de los cheques ($%s) debe igualar el '
                        . 'importe recibido del recibo ($%s).',
                        number_format($sumaCheques, 2, ',', '.'),
                        number_format($cobrado, 2, ',', '.'),
                    ));
                }
                foreach ($chequesData as $chequeData) {
                    $this->cheques->crearDesdeRecibo(
                        reciboId: $recibo->id,
                        empresaId: $empresaId,
                        data: $chequeData,
                        userId: $usuario->id,
                    );
                }
            }

            $this->audit->logEvento(
                accion: 'RECIBO_EMITIDO',
                modulo: 'tesoreria',
                descripcion: sprintf('Recibo %s emitido (asiento #%d, total cancelado $%.2f)',
                    $recibo->numero_correlativo, $asiento->id, $totalCancelado ?? (float) $recibo->total_factura),
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
                    'origen' => 'COBRO', // ENUM erp_asientos no incluye RECIBO_ANULADO
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

            // Marcar el recibo ANULADO PRIMERO, para que saldoFactura() lo
            // excluya al recalcular el estado de las facturas.
            $recibo->update([
                'estado' => Recibo::ESTADO_ANULADO,
                'anulado_at' => now(),
                'anulado_por_user_id' => $usuario->id,
                'anulado_motivo' => $motivo,
            ]);

            // Liberar TODAS las facturas que estaban imputadas en el recibo
            // (multi-comprobante). Antes solo se reseteaba la factura legacy
            // (factura_venta_id), así que en recibos con varias facturas el
            // resto quedaba pegado en COBRADA y no volvía a aparecer como
            // disponible. Recalculamos el estado real de cada una según su
            // saldo (puede tener otros recibos vigentes → COBRO_PARCIAL).
            $facturaIds = DB::table('erp_recibos_comprobantes_imputados')
                ->where('recibo_id', $recibo->id)->pluck('factura_venta_id')->all();
            if (empty($facturaIds) && $recibo->factura_venta_id) {
                $facturaIds = [$recibo->factura_venta_id]; // back-compat 1:1 (v1.31)
            }
            foreach (array_unique($facturaIds) as $fid) {
                $impTotal = (float) DB::table('erp_facturas_venta')->where('id', $fid)->value('imp_total');
                $saldo = $this->saldoFactura((int) $fid, $recibo->empresa_id);
                if ($saldo <= 0.01) {
                    $estado = 'COBRADA';
                } elseif ($saldo >= $impTotal - 0.01) {
                    $estado = 'EMITIDA';
                } else {
                    $estado = 'COBRO_PARCIAL';
                }
                DB::table('erp_facturas_venta')->where('id', $fid)
                    ->update(['estado' => $estado, 'updated_at' => now()]);
            }

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
    /**
     * v1.32 — ¿El medio de cobro es la cuenta especial "Compensación"
     * (codigo COMP_CC)? En ese caso el recibo es contablemente neutro:
     * NC y facturas pueden estar incluso con saldo 0 (la operación queda
     * registrada como D=H sobre Deudores por Ventas, sin tocar saldos).
     */
    public function esCompensacion(?int $medioCobroId): bool
    {
        if (! $medioCobroId) return false;
        return DB::table('erp_cuentas_bancarias')
            ->where('id', $medioCobroId)->value('codigo') === 'COMP_CC';
    }

    public function medioEsChequesCartera(?int $medioCobroId): bool
    {
        if (! $medioCobroId) return false;
        return DB::table('erp_cuentas_bancarias')
            ->where('id', $medioCobroId)->value('codigo') === 'CHEQUES_CARTERA';
    }

    /**
     * Fecha de cobro = la que define el operador (fecha económica del cobro).
     * Debe ser igual o anterior a la de emisión (hoy). Default: hoy.
     */
    private function resolverFechaCobro(array $data): string
    {
        $hoy = today()->toDateString();
        $fc = ! empty($data['fecha_cobro']) ? substr((string) $data['fecha_cobro'], 0, 10) : $hoy;
        if ($fc > $hoy) {
            throw new DomainException('FECHA_COBRO_FUTURA: la fecha de cobro no puede ser posterior a la fecha de emisión (hoy).');
        }
        return $fc;
    }

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
    public function saldoFactura(int $facturaId, int $empresaId = 1, ?int $excludeReciboId = null): float
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

        // v1.32 — Imputado via recibos (multi-comprobante). Cada fila de
        // comprobantes_imputados tiene su monto_imputado, que es lo que el
        // recibo consumió de esta factura específicamente.
        // `excludeReciboId` permite excluir el recibo que está siendo editado
        // (ver actualizar()): sin esto el saldo daba falso "sobre imputado"
        // porque el monto_cobrado del recibo (suma de TODAS sus facturas)
        // se sumaba a la factura puntual via el fallback v1.31.
        $q = DB::table('erp_recibos_comprobantes_imputados as rci')
            ->join('erp_recibos as r', 'r.id', '=', 'rci.recibo_id')
            ->where('rci.factura_venta_id', $facturaId)
            ->where('r.estado', '!=', Recibo::ESTADO_ANULADO);
        if ($excludeReciboId !== null) $q->where('rci.recibo_id', '!=', $excludeReciboId);
        $imputadoRecibos = (float) $q->sum('rci.monto_imputado');

        // Fallback v1.31: si NO hay imputaciones via tabla nueva pero sí hay
        // recibos legacy (1:1) referenciados via factura_venta_id, sumar su monto_cobrado.
        if ($imputadoRecibos < 0.01) {
            $q2 = DB::table('erp_recibos')
                ->where('factura_venta_id', $facturaId)
                ->where('estado', '!=', Recibo::ESTADO_ANULADO)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('erp_recibos_comprobantes_imputados as rci2')
                      ->whereColumn('rci2.recibo_id', 'erp_recibos.id');
                });
            if ($excludeReciboId !== null) $q2->where('id', '!=', $excludeReciboId);
            $imputadoRecibos = (float) $q2->sum('monto_cobrado');
        }

        // Si la factura está imputada en recibos, los cobros legacy del v1.15
        // ya están reflejados via la tabla puente (no doble contamos).
        $tieneRecibo = DB::table('erp_recibos')
            ->whereNotIn('estado', [Recibo::ESTADO_ANULADO])
            ->where(function ($q) use ($facturaId) {
                $q->where('factura_venta_id', $facturaId)
                  ->orWhereExists(function ($q2) use ($facturaId) {
                      $q2->select(DB::raw(1))
                         ->from('erp_recibos_comprobantes_imputados as rci3')
                         ->whereColumn('rci3.recibo_id', 'erp_recibos.id')
                         ->where('rci3.factura_venta_id', $facturaId);
                  });
            })->exists();
        $cobroEfectivo = $tieneRecibo ? $imputadoRecibos : $cobrado;

        return round((float) $factura->imp_total - $cobroEfectivo - $ncImputada, 2);
    }

    /**
     * v1.32 D-32-2 — Próximo número de recibo sincronizado con DistriApp.
     *
     * Política: tomar max(local secuencia, último DistriApp) + 1. Lock pesimista
     * sobre erp_secuencias_recibo evita race local. Si DistriApp emite entre
     * nuestra consulta y nuestro update, el FK UNIQUE de (punto_venta, numero)
     * detecta el conflicto y el caller debe reintentar.
     */
    public function siguienteNumeroSincronizado(string $puntoVenta = self::PV_DEFAULT): string
    {
        return DB::transaction(function () use ($puntoVenta) {
            $secuencia = DB::table('erp_secuencias_recibo')
                ->where('punto_venta', $puntoVenta)
                ->lockForUpdate()
                ->first();
            if (! $secuencia) {
                // Seed inicial si el PV no existe.
                DB::table('erp_secuencias_recibo')->insert([
                    'punto_venta' => $puntoVenta,
                    'ultimo_numero' => 0,
                    'ultimo_emitido_por' => 'ERP',
                    'ultimo_emitido_at' => now(),
                ]);
                $secuencia = (object) ['ultimo_numero' => 0];
            }

            $maxDistriapp = $this->distri->ultimoNumeroRecibo($puntoVenta);
            $maxLocal = (int) $secuencia->ultimo_numero;
            $proximo = max($maxLocal, $maxDistriapp) + 1;

            DB::table('erp_secuencias_recibo')
                ->where('punto_venta', $puntoVenta)
                ->update([
                    'ultimo_numero' => $proximo,
                    'ultimo_emitido_por' => 'ERP',
                    'ultimo_emitido_at' => now(),
                ]);

            return str_pad((string) $proximo, 8, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Expande el enum compacto de erp_empresas a texto humano (para snapshot
     * del recibo, mismo formato que DistriApp).
     */
    private function expandirCondicionIva(?string $codigo): string
    {
        return match ($codigo) {
            'RI' => 'I.V.A. RESPONSABLE INSCRIPTO',
            'MONOTRIBUTO' => 'MONOTRIBUTO',
            'EXENTO' => 'IVA EXENTO',
            'CF' => 'CONSUMIDOR FINAL',
            default => $codigo ?? '',
        };
    }

    private function admiteCc(int $cuentaId): bool
    {
        // La columna real es `admite_cc` (tinyint). El resto del código usa
        // ese nombre; acá había quedado el largo `admite_centro_costo` y
        // MariaDB tiraba 1054 Unknown column → 500 al emitir recibo.
        return (bool) DB::table('erp_cuentas_contables')
            ->where('id', $cuentaId)->value('admite_cc');
    }

    /**
     * v1.34 — IDs de auxiliares que representan el mismo cliente real (mismo CUIT).
     *
     * @return list<int>
     */
    private function auxiliaresHermanos(int $clienteId, int $empresaId): array
    {
        $cuit = DB::table('erp_auxiliares')
            ->where('id', $clienteId)->where('empresa_id', $empresaId)
            ->value('cuit');
        if (! $cuit) {
            return [$clienteId];
        }
        return DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', 'Cliente')
            ->where('cuit', $cuit)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
