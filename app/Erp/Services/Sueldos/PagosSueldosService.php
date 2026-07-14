<?php

namespace App\Erp\Services\Sueldos;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\MovimientoAsiento;
use App\Erp\Models\Periodo;
use App\Erp\Models\Sueldos\Concepto;
use App\Erp\Models\Sueldos\Empleado;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\LiquidacionItem;
use App\Erp\Models\Sueldos\Pago;
use App\Erp\Models\Sueldos\Prestamo;
use App\Erp\Models\Tesoreria\OrdenPago;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de sueldos en 3 modalidades + asientos contables (SPEC 08 §5.6 + §6).
 *
 * - contabilizarDevengo: al aprobar la liquidación, genera el asiento de
 *   devengo agrupando líneas por cuenta (sin auxiliar por empleado en V1).
 * - pagarFormal: una OP por empleado con neto FORMAL > 0 + asiento de pago.
 * - pagarEfectivo: registra pagos con receptor (RN-112) + asiento.
 * - pagarMt: una OP por empleado MT contra su factura compra C + asiento.
 *
 * Asiento de devengo (resumido):
 *   Por cada item con signo HABER:        DEBE cuenta_debe_id
 *   Por cada item con signo DESCUENTO:    DEBE cuenta_debe_id (suele ser
 *     2.1.5.10 Sueldos a pagar para reducir el neto)
 *   Y la contrapartida HABER del item correspondiente.
 *   Se agrupan por cuenta_id; debe y haber compensan el balance.
 */
class PagosSueldosService
{
    public function contabilizarDevengo(Liquidacion $liq, ?int $userId = null): Asiento
    {
        if ($liq->asiento_id) {
            throw new DomainException('YA_CONTABILIZADA: la liquidación #'.$liq->id.' ya tiene asiento #'.$liq->asiento_id);
        }
        if (! in_array($liq->estado, [Liquidacion::ESTADO_CALCULADA, Liquidacion::ESTADO_APROBADA], true)) {
            throw new DomainException('ESTADO_INVALIDO: para contabilizar devengo requiere CALCULADA o APROBADA (actual: '.$liq->estado.').');
        }

        $items = LiquidacionItem::with('concepto', 'empleado')
            ->where('liquidacion_id', $liq->id)->get();
        if ($items->isEmpty()) {
            throw new DomainException('SIN_ITEMS: la liquidación no tiene items para contabilizar.');
        }

        // Cuentas usadas en el asiento — saber cuáles piden auxiliar y/o CC.
        $cuentaIdsUsadas = [];
        foreach ($items as $it) {
            $cuentaIdsUsadas[] = (int) $it->concepto->cuenta_debe_id;
            $cuentaIdsUsadas[] = (int) $it->concepto->cuenta_haber_id;
        }
        $cuentaIdsUsadas = array_values(array_unique(array_filter($cuentaIdsUsadas)));
        $cuentasMeta = $this->metadataCuentas($cuentaIdsUsadas);

        // Pre-resolver auxiliares de los empleados involucrados.
        $auxByEmpleado = [];
        foreach ($items->pluck('empleado')->unique('id') as $emp) {
            $auxByEmpleado[$emp->id] = $this->auxiliarEmpleado($emp)->id;
        }

        // Agrupar por (cuenta_id, auxiliar_id) — auxiliar=NULL si la cuenta no lo requiere.
        $agrupado = [];
        foreach ($items as $it) {
            $imp = (float) $it->importe;
            $debeCta  = (int) $it->concepto->cuenta_debe_id;
            $haberCta = (int) $it->concepto->cuenta_haber_id;
            if (! $debeCta || ! $haberCta) continue;

            $auxDebe  = ($cuentasMeta[$debeCta]['admite_auxiliar']  ?? false) ? ($auxByEmpleado[$it->empleado_id] ?? null) : null;
            $auxHaber = ($cuentasMeta[$haberCta]['admite_auxiliar'] ?? false) ? ($auxByEmpleado[$it->empleado_id] ?? null) : null;

            $kDebe  = $debeCta.'|'.($auxDebe  ?? 0);
            $kHaber = $haberCta.'|'.($auxHaber ?? 0);

            $agrupado[$kDebe]  = $agrupado[$kDebe]  ?? ['cuenta_id' => $debeCta,  'auxiliar_id' => $auxDebe,  'debe' => 0, 'haber' => 0];
            $agrupado[$kHaber] = $agrupado[$kHaber] ?? ['cuenta_id' => $haberCta, 'auxiliar_id' => $auxHaber, 'debe' => 0, 'haber' => 0];
            $agrupado[$kDebe]['debe']   += $imp;
            $agrupado[$kHaber]['haber'] += $imp;
        }

        $fecha = $liq->periodo.'-'.date('t', strtotime($liq->periodo.'-01'));

        return DB::transaction(function () use ($liq, $agrupado, $cuentasMeta, $fecha, $userId) {
            $asiento = $this->crearAsiento(
                fecha: $fecha,
                glosa: 'Devengo sueldos '.$liq->periodo.' ('.$liq->tipo.')',
                origenTabla: 'erp_emp_liquidaciones',
                origenId: $liq->id,
                userId: $userId,
            );

            $ccDefaultId = $this->ccDefault();

            $linea = 1;
            $totalDebe = 0; $totalHaber = 0;
            foreach ($agrupado as $r) {
                $debe  = round($r['debe']  ?? 0, 2);
                $haber = round($r['haber'] ?? 0, 2);
                $neto  = round($debe - $haber, 2);
                if ($neto == 0) continue;

                $cuentaId = (int) $r['cuenta_id'];
                $admiteCc = $cuentasMeta[$cuentaId]['admite_cc'] ?? false;
                $debeFinal  = $neto > 0 ? $neto : 0;
                $haberFinal = $neto < 0 ? -$neto : 0;

                MovimientoAsiento::create([
                    'asiento_id'      => $asiento->id,
                    'linea'           => $linea++,
                    'cuenta_id'       => $cuentaId,
                    'auxiliar_id'     => $r['auxiliar_id'],
                    'centro_costo_id' => $admiteCc ? $ccDefaultId : null,
                    'debe'            => $debeFinal,
                    'haber'           => $haberFinal,
                    'moneda'          => 'ARS',
                ]);
                $totalDebe  += $debeFinal;
                $totalHaber += $haberFinal;
            }

            $asiento->update([
                'total_debe'  => $totalDebe,
                'total_haber' => $totalHaber,
                'estado'      => 'CONTABILIZADO',
                'fecha_contabilizacion' => now(),
            ]);

            $liq->update(['asiento_id' => $asiento->id]);

            return $asiento;
        });
    }

    public function pagarFormal(Liquidacion $liq, int $cuentaBancariaId, string $fecha, ?int $userId = null): array
    {
        $this->validarLiquidacionPagable($liq);

        $cuentaBanco = DB::table('erp_cuentas_bancarias')->where('id', $cuentaBancariaId)->first();
        if (! $cuentaBanco) {
            throw new DomainException('CUENTA_BANCARIA_INVALIDA');
        }

        $netos = $this->netosPorEmpleadoYComponente($liq);
        $cuentaSueldosPagarId = $this->cuentaPorCodigo('2.1.5.10');

        $resultado = ['ops' => [], 'pagos' => []];

        DB::transaction(function () use ($liq, $netos, $cuentaBanco, $cuentaSueldosPagarId, $fecha, $userId, &$resultado) {
            foreach ($netos as $empId => $componentes) {
                $importe = round((float) ($componentes['FORMAL'] ?? 0), 2);
                if ($importe <= 0) continue;

                if ($this->pagoExiste($liq->id, $empId, 'FORMAL')) {
                    continue;
                }

                $emp = Empleado::find($empId);
                if (! $emp) continue;

                $auxiliar = $this->auxiliarEmpleado($emp);

                $op = OrdenPago::create([
                    'empresa_id'         => 1,
                    'numero'             => $this->proximoNumeroOp(),
                    'fecha'              => $fecha,
                    'tipo'               => 'OTROS',
                    'auxiliar_id'        => $auxiliar->id,
                    'moneda_id'          => 1,
                    'cotizacion'         => 1,
                    'importe'            => $importe,
                    'importe_bruto'      => $importe,
                    'total_retenciones'  => 0,
                    'estado'             => OrdenPago::ESTADO_LIBERADA,
                    'concepto'           => 'Sueldo '.$liq->periodo.' '.$emp->apellido.', '.$emp->nombre,
                    'fecha_liberacion'   => now(),
                    'creado_por_user_id' => $userId,
                ]);

                $asiento = $this->crearAsientoPago(
                    fecha: $fecha,
                    glosa: 'Pago FORMAL sueldo '.$liq->periodo.' '.$emp->apellido.', '.$emp->nombre,
                    cuentaDebeId: $cuentaSueldosPagarId,
                    cuentaHaberId: (int) $cuentaBanco->cuenta_contable_id,
                    importe: $importe,
                    auxiliarId: $auxiliar->id,
                    userId: $userId,
                    origenTabla: 'erp_ordenes_pago',
                    origenId: $op->id,
                );

                $op->update(['asiento_id' => $asiento->id, 'estado' => OrdenPago::ESTADO_PAGADA, 'fecha_pago' => now()]);

                $pago = Pago::create([
                    'liquidacion_id'    => $liq->id,
                    'empleado_id'       => $emp->id,
                    'componente'        => 'FORMAL',
                    'medio'             => Pago::MEDIO_TRANSFERENCIA,
                    'importe'           => $importe,
                    'fecha'             => $fecha,
                    'orden_pago_id'     => $op->id,
                    'cbu_destino'       => $emp->cbu,
                    'banco_destino'     => $emp->banco,
                    'asiento_id'        => $asiento->id,
                    'created_at'        => now(),
                ]);

                $resultado['ops'][]   = $op->id;
                $resultado['pagos'][] = $pago->id;
            }

            $this->actualizarEstadoLiquidacion($liq);
        });

        return $resultado;
    }

    public function pagarEfectivo(Liquidacion $liq, int $cajaId, string $fecha, array $receptores, ?int $userId = null): array
    {
        $this->validarLiquidacionPagable($liq);

        $caja = DB::table('erp_cajas')->where('id', $cajaId)->first();
        if (! $caja) {
            throw new DomainException('CAJA_INVALIDA');
        }

        $netos = $this->netosPorEmpleadoYComponente($liq);
        $cuentaSueldosPagarId = $this->cuentaPorCodigo('2.1.5.10');

        $byEmpleado = collect($receptores)->keyBy('empleado_id');
        $resultado = ['pagos' => []];

        DB::transaction(function () use ($liq, $netos, $caja, $cuentaSueldosPagarId, $fecha, $byEmpleado, $userId, &$resultado) {
            foreach ($netos as $empId => $componentes) {
                $importe = round((float) ($componentes['EFECTIVO'] ?? 0), 2);
                if ($importe <= 0) continue;
                if ($this->pagoExiste($liq->id, $empId, 'EFECTIVO')) continue;

                $rec = $byEmpleado->get($empId);
                if (! $rec || empty($rec['recibido_por']) || empty($rec['dni_recibio'])) {
                    throw new DomainException('RECEPTOR_FALTANTE: empleado #'.$empId.' requiere recibido_por + dni_recibio.');
                }

                $emp = Empleado::find($empId);
                if (! $emp) continue;

                $asiento = $this->crearAsientoPago(
                    fecha: $fecha,
                    glosa: 'Pago EFECTIVO sueldo '.$liq->periodo.' '.$emp->apellido.', '.$emp->nombre,
                    cuentaDebeId: $cuentaSueldosPagarId,
                    cuentaHaberId: (int) $caja->cuenta_contable_id,
                    importe: $importe,
                    auxiliarId: $this->auxiliarEmpleado($emp)->id,
                    userId: $userId,
                    origenTabla: 'erp_emp_pagos',
                    origenId: 0,
                );

                $pago = Pago::create([
                    'liquidacion_id'  => $liq->id,
                    'empleado_id'     => $emp->id,
                    'componente'      => 'EFECTIVO',
                    'medio'           => Pago::MEDIO_EFECTIVO,
                    'importe'         => $importe,
                    'fecha'           => $fecha,
                    'recibido_por'    => $rec['recibido_por'],
                    'dni_recibio'     => $rec['dni_recibio'],
                    'asiento_id'      => $asiento->id,
                    'observaciones'   => 'Caja '.$caja->codigo,
                    'created_at'      => now(),
                ]);

                // Actualizar referencia del asiento a este pago (origen_id real).
                $asiento->update(['origen_id' => $pago->id]);

                $resultado['pagos'][] = $pago->id;
            }

            $this->actualizarEstadoLiquidacion($liq);
        });

        return $resultado;
    }

    public function pagarMt(Liquidacion $liq, int $cuentaBancariaId, string $fecha, array $facturas, ?int $userId = null): array
    {
        $this->validarLiquidacionPagable($liq);

        $cuentaBanco = DB::table('erp_cuentas_bancarias')->where('id', $cuentaBancariaId)->first();
        if (! $cuentaBanco) {
            throw new DomainException('CUENTA_BANCARIA_INVALIDA');
        }

        $netos = $this->netosPorEmpleadoYComponente($liq);
        $cuentaHonorariosPagarId = $this->cuentaPorCodigo('2.1.5.05');

        $byEmpleado = collect($facturas)->keyBy('empleado_id');
        $resultado = ['ops' => [], 'pagos' => []];

        DB::transaction(function () use ($liq, $netos, $cuentaBanco, $cuentaHonorariosPagarId, $fecha, $byEmpleado, $userId, &$resultado) {
            foreach ($netos as $empId => $componentes) {
                $importe = round((float) ($componentes['MT'] ?? 0), 2);
                if ($importe <= 0) continue;
                if ($this->pagoExiste($liq->id, $empId, 'MT')) continue;

                $rec = $byEmpleado->get($empId);
                if (! $rec || empty($rec['factura_compra_id'])) {
                    throw new DomainException('FACTURA_MT_FALTANTE: empleado #'.$empId.' (MT) requiere factura_compra_id.');
                }

                $emp = Empleado::find($empId);
                if (! $emp) continue;

                $auxiliar = $this->auxiliarEmpleado($emp);

                $op = OrdenPago::create([
                    'empresa_id'         => 1,
                    'numero'             => $this->proximoNumeroOp(),
                    'fecha'              => $fecha,
                    'tipo'               => 'OTROS',
                    'auxiliar_id'        => $auxiliar->id,
                    'moneda_id'          => 1,
                    'cotizacion'         => 1,
                    'importe'            => $importe,
                    'importe_bruto'      => $importe,
                    'total_retenciones'  => 0,
                    'estado'             => OrdenPago::ESTADO_LIBERADA,
                    'concepto'           => 'Honorarios MT '.$liq->periodo.' '.$emp->apellido.', '.$emp->nombre.' (FC #'.$rec['factura_compra_id'].')',
                    'fecha_liberacion'   => now(),
                    'creado_por_user_id' => $userId,
                ]);

                $asiento = $this->crearAsientoPago(
                    fecha: $fecha,
                    glosa: 'Pago MT '.$liq->periodo.' '.$emp->apellido.', '.$emp->nombre,
                    cuentaDebeId: $cuentaHonorariosPagarId,
                    cuentaHaberId: (int) $cuentaBanco->cuenta_contable_id,
                    importe: $importe,
                    auxiliarId: $auxiliar->id,
                    userId: $userId,
                    origenTabla: 'erp_ordenes_pago',
                    origenId: $op->id,
                );

                $op->update(['asiento_id' => $asiento->id, 'estado' => OrdenPago::ESTADO_PAGADA, 'fecha_pago' => now()]);

                $pago = Pago::create([
                    'liquidacion_id'    => $liq->id,
                    'empleado_id'       => $emp->id,
                    'componente'        => 'MT',
                    'medio'             => Pago::MEDIO_TRANSFERENCIA,
                    'importe'           => $importe,
                    'fecha'             => $fecha,
                    'orden_pago_id'     => $op->id,
                    'factura_compra_id' => $rec['factura_compra_id'],
                    'cbu_destino'       => $emp->cbu,
                    'banco_destino'     => $emp->banco,
                    'asiento_id'        => $asiento->id,
                    'created_at'        => now(),
                ]);

                $resultado['ops'][]   = $op->id;
                $resultado['pagos'][] = $pago->id;
            }

            $this->actualizarEstadoLiquidacion($liq);
        });

        return $resultado;
    }

    // ============================================================ helpers

    private function netosPorEmpleadoYComponente(Liquidacion $liq): array
    {
        $rows = DB::table('erp_emp_liquidaciones_items as li')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'li.concepto_id')
            ->where('li.liquidacion_id', $liq->id)
            ->select('li.empleado_id', 'li.componente', 'c.signo', DB::raw('SUM(li.importe) AS total'))
            ->groupBy('li.empleado_id', 'li.componente', 'c.signo')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $delta = (float) $r->total * ($r->signo === Concepto::SIGNO_HABER ? 1 : -1);
            $out[$r->empleado_id][$r->componente] = ($out[$r->empleado_id][$r->componente] ?? 0) + $delta;
        }
        return $out;
    }

    private function pagoExiste(int $liqId, int $empleadoId, string $componente): bool
    {
        return Pago::where('liquidacion_id', $liqId)
            ->where('empleado_id', $empleadoId)
            ->where('componente', $componente)
            ->exists();
    }

    private function validarLiquidacionPagable(Liquidacion $liq): void
    {
        if ($liq->estado !== Liquidacion::ESTADO_APROBADA) {
            throw new DomainException('ESTADO_INVALIDO: la liquidación debe estar APROBADA para pagar (actual: '.$liq->estado.').');
        }
    }

    private function actualizarEstadoLiquidacion(Liquidacion $liq): void
    {
        $netos = $this->netosPorEmpleadoYComponente($liq);
        $componentesActivos = [];
        foreach ($netos as $componentes) {
            foreach (['FORMAL', 'EFECTIVO', 'MT'] as $c) {
                if (round((float) ($componentes[$c] ?? 0), 2) > 0) {
                    $componentesActivos[$c] = true;
                }
            }
        }

        $todosPagados = true;
        foreach (array_keys($componentesActivos) as $comp) {
            $netoComp = 0;
            foreach ($netos as $componentes) {
                $netoComp += round((float) ($componentes[$comp] ?? 0), 2);
            }
            $pagadoComp = (float) Pago::where('liquidacion_id', $liq->id)
                ->where('componente', $comp)->sum('importe');
            if (abs($netoComp - $pagadoComp) > 0.5) {
                $todosPagados = false;
                break;
            }
        }

        if ($todosPagados && count($componentesActivos) > 0) {
            $liq->update(['estado' => Liquidacion::ESTADO_PAGADA, 'fecha_pago' => now()]);
            $this->avanzarPrestamosCuotas($liq);
        }
    }

    private function avanzarPrestamosCuotas(Liquidacion $liq): void
    {
        // G-13 (gap analysis 2026-07-13): avanzar SOLO los préstamos cuya
        // cuota fue imputada en ESTA liquidación. El criterio anterior
        // (todo préstamo VIGENTE de la empresa con primera_cuota <= período)
        // avanzaba préstamos de empleados no liquidados o otorgados después
        // del cálculo. La fuente de verdad son los ítems PRESTAMO_CUOTA,
        // cuya observación lleva 'Préstamo #{id}' (la escribe
        // LiquidacionService::descuentosInternos).
        $prestamoIds = [];
        $observaciones = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)
            ->where('c.codigo', 'PRESTAMO_CUOTA')
            ->pluck('i.observaciones');
        foreach ($observaciones as $obs) {
            if (preg_match('/#(\d+)\b/u', (string) $obs, $m)) {
                $prestamoIds[(int) $m[1]] = true;
            }
        }
        if (empty($prestamoIds)) {
            return;
        }

        $prestamos = Prestamo::whereIn('id', array_keys($prestamoIds))
            ->where('estado', Prestamo::ESTADO_VIGENTE)
            ->whereColumn('cuotas_pagadas', '<', 'cuotas_total')
            ->get();
        foreach ($prestamos as $p) {
            $nuevaCuota = $p->cuotas_pagadas + 1;
            $nuevoSaldo = round((float) $p->saldo_capital - (float) $p->cuota_mensual, 2);
            $update = [
                'cuotas_pagadas' => $nuevaCuota,
                'saldo_capital'  => max(0, $nuevoSaldo),
            ];
            if ($nuevaCuota >= $p->cuotas_total || $nuevoSaldo <= 0.005) {
                $update['estado']        = Prestamo::ESTADO_CANCELADO;
                $update['saldo_capital'] = 0;
            }
            $p->update($update);
        }
    }

    private function auxiliarEmpleado(Empleado $emp): Auxiliar
    {
        return Auxiliar::firstOrCreate(
            ['empresa_id' => 1, 'tipo' => 'Empleado', 'codigo' => 'EMP-'.$emp->legajo],
            [
                'nombre'    => $emp->apellido.', '.$emp->nombre,
                'cuit'      => $emp->cuil ? preg_replace('/[^0-9]/', '', $emp->cuil) : null,
                'tabla_ref' => 'erp_emp_empleados',
                'id_ref'    => $emp->id,
                'activo'    => 1,
            ]
        );
    }

    /** Centro de costo default para cuentas con admite_cc=1 (los gastos de sueldos). */
    private function ccDefault(): int
    {
        $id = DB::table('erp_centros_costo')->where('empresa_id', 1)->where('activo', 1)
            ->orderBy('id')->value('id');
        if (! $id) {
            throw new DomainException('CC_DEFAULT_NO_ENCONTRADO: cargá al menos un centro de costo activo.');
        }
        return (int) $id;
    }

    /** @param int[] $cuentaIds @return int[] */
    private function cuentasQueRequierenCC(array $cuentaIds): array
    {
        if (empty($cuentaIds)) return [];
        return DB::table('erp_cuentas_contables')
            ->whereIn('id', $cuentaIds)->where('admite_cc', 1)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param int[] $cuentaIds
     * @return array<int, array{admite_cc: bool, admite_auxiliar: bool, tipo_auxiliar: ?string}>
     */
    private function metadataCuentas(array $cuentaIds): array
    {
        if (empty($cuentaIds)) return [];
        $rows = DB::table('erp_cuentas_contables')
            ->whereIn('id', $cuentaIds)
            ->select('id', 'admite_cc', 'admite_auxiliar', 'tipo_auxiliar')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = [
                'admite_cc'       => (bool) $r->admite_cc,
                'admite_auxiliar' => (bool) $r->admite_auxiliar,
                'tipo_auxiliar'   => $r->tipo_auxiliar,
            ];
        }
        return $out;
    }

    private function cuentaPorCodigo(string $codigo): int
    {
        $id = CuentaContable::where('empresa_id', 1)->where('codigo', $codigo)->value('id');
        if (! $id) {
            throw new DomainException('CUENTA_NO_ENCONTRADA: '.$codigo);
        }
        return (int) $id;
    }

    private function crearAsiento(string $fecha, string $glosa, string $origenTabla, int $origenId, ?int $userId): Asiento
    {
        [$ejercicioId, $periodoId] = $this->resolverEjercicioYPeriodo($fecha);

        return Asiento::create([
            'empresa_id'    => 1,
            'ejercicio_id'  => $ejercicioId,
            'periodo_id'    => $periodoId,
            'diario_id'     => 11, // NOM
            'numero'        => $this->proximoNumeroAsiento(),
            'fecha'         => $fecha,
            'glosa'         => $glosa,
            'origen'        => 'NOMINA',
            'origen_id'     => $origenId,
            'origen_tabla'  => $origenTabla,
            'estado'        => 'BORRADOR',
            'moneda_base'   => 'ARS',
            'total_debe'    => 0,
            'total_haber'   => 0,
            'usuario_id'    => $userId,
        ]);
    }

    private function crearAsientoPago(
        string $fecha, string $glosa,
        int $cuentaDebeId, int $cuentaHaberId, float $importe,
        ?int $auxiliarId, ?int $userId,
        string $origenTabla, int $origenId
    ): Asiento {
        $asiento = $this->crearAsiento($fecha, $glosa, $origenTabla, $origenId, $userId);

        $ccDefaultId = $this->ccDefault();
        $req = $this->cuentasQueRequierenCC([$cuentaDebeId, $cuentaHaberId]);

        MovimientoAsiento::create([
            'asiento_id'      => $asiento->id, 'linea' => 1,
            'cuenta_id'       => $cuentaDebeId,
            'auxiliar_id'     => $auxiliarId,
            'centro_costo_id' => in_array($cuentaDebeId, $req, true) ? $ccDefaultId : null,
            'debe'            => $importe, 'haber' => 0, 'moneda' => 'ARS',
        ]);
        MovimientoAsiento::create([
            'asiento_id'      => $asiento->id, 'linea' => 2,
            'cuenta_id'       => $cuentaHaberId,
            'centro_costo_id' => in_array($cuentaHaberId, $req, true) ? $ccDefaultId : null,
            'debe'            => 0, 'haber' => $importe, 'moneda' => 'ARS',
        ]);

        $asiento->update([
            'total_debe'  => $importe,
            'total_haber' => $importe,
            'estado'      => 'CONTABILIZADO',
            'fecha_contabilizacion' => now(),
        ]);

        return $asiento;
    }

    private function resolverEjercicioYPeriodo(string $fecha): array
    {
        $p = Periodo::where('fecha_inicio', '<=', $fecha)
            ->where('fecha_fin', '>=', $fecha)
            ->where('estado', '!=', 'CERRADO')
            ->orderByDesc('fecha_inicio')
            ->first();
        if (! $p) {
            throw new DomainException('PERIODO_NO_ENCONTRADO: no hay período abierto que contenga '.$fecha);
        }
        return [(int) $p->ejercicio_id, (int) $p->id];
    }

    private function proximoNumeroAsiento(): string
    {
        $row = DB::table('erp_asientos')
            ->where('empresa_id', 1)
            ->orderByDesc('id')
            ->limit(1)
            ->first(['numero']);
        $n = $row ? ((int) preg_replace('/\D/', '', (string) $row->numero) + 1) : 1;
        return str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    private function proximoNumeroOp(): string
    {
        $row = DB::table('erp_ordenes_pago')
            ->where('empresa_id', 1)
            ->orderByDesc('id')
            ->limit(1)
            ->first(['numero']);
        $n = $row ? ((int) preg_replace('/\D/', '', (string) $row->numero) + 1) : 1;
        return 'OP-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }
}
