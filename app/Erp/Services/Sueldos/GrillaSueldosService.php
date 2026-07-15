<?php

namespace App\Erp\Services\Sueldos;

use App\Erp\Models\Sueldos\Concepto;
use App\Erp\Models\Sueldos\Empleado;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Novedad;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Workstream Sueldos Bloque 3 — la grilla editable estilo Excel (P8):
 * una fila por empleado con días, columnas de haberes/descuentos
 * manuales (novedades del período), reparto y totales; un solo guardar
 * que persiste todo y recalcula la liquidación.
 */
class GrillaSueldosService
{
    /** Columnas manuales de la grilla, en el orden del Excel. */
    private const CONCEPTOS_GRILLA = [
        'HE_50', 'HE_100', 'BONO_PROD', 'COMISION', 'VIATICO', 'AUMENTO_GERENCIAL',
        'ADELANTO', 'COMBUSTIBLE', 'POLIZA', 'SANCION', 'HORAS_DESC', 'FALTA_DIA', 'AJUSTE_MANUAL',
    ];

    /** Conceptos cuya carga natural es por cantidad (horas/días). */
    private const POR_CANTIDAD = ['HE_50', 'HE_100', 'HORAS_DESC', 'FALTA_DIA'];

    public function __construct(
        private readonly LiquidacionService $liquidaciones,
        private readonly PagosSueldosService $pagos,
    ) {}

    public function armar(Liquidacion $liq): array
    {
        $conceptos = Concepto::whereIn('codigo', self::CONCEPTOS_GRILLA)
            ->where('activo', 1)->get()->keyBy('codigo');

        $empleados = Empleado::where('activo', 1)->orderBy('legajo')->get();
        $overrides = DB::table('erp_emp_liquidacion_reparto_override')
            ->where('liquidacion_id', $liq->id)->get()->keyBy('empleado_id');
        $novedades = Novedad::with('concepto:id,codigo')
            ->where('periodo', $liq->periodo)->get()->groupBy('empleado_id');

        // Totales por empleado desde los ítems ya calculados.
        $porEmpleado = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)
            ->groupBy('i.empleado_id')
            ->selectRaw("i.empleado_id,
                SUM(CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) neto,
                SUM(CASE WHEN i.componente='FORMAL'   THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) formal,
                SUM(CASE WHEN i.componente='EFECTIVO' THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) efectivo,
                SUM(CASE WHEN i.componente='MT'       THEN (CASE WHEN c.signo='HABER' THEN i.importe ELSE -i.importe END) ELSE 0 END) mt")
            ->get()->keyBy('empleado_id');

        $itemsCalculados = DB::table('erp_emp_liquidaciones_items as i')
            ->join('erp_emp_conceptos as c', 'c.id', '=', 'i.concepto_id')
            ->where('i.liquidacion_id', $liq->id)
            ->whereIn('c.codigo', array_merge(self::CONCEPTOS_GRILLA, ['PRESTAMO_CUOTA']))
            ->groupBy('i.empleado_id', 'c.codigo')
            ->selectRaw('i.empleado_id, c.codigo, SUM(i.importe) importe')
            ->get()->groupBy('empleado_id');

        $cierre = $liq->periodo.'-'.date('t', strtotime($liq->periodo.'-01'));

        $filas = [];
        foreach ($empleados as $emp) {
            $ov = $overrides->get($emp->id);
            $basico = DB::table('erp_emp_basicos_historial')
                ->where('empleado_id', $emp->id)
                ->where('vigencia_desde', '<=', $cierre)
                ->where(fn ($q) => $q->whereNull('vigencia_hasta')->orWhere('vigencia_hasta', '>=', $cierre))
                ->orderByDesc('vigencia_desde')->value('basico_total');

            $valores = [];
            foreach ($novedades->get($emp->id, collect()) as $nov) {
                $cod = $nov->concepto?->codigo;
                if (! $cod || ! in_array($cod, self::CONCEPTOS_GRILLA, true)) {
                    continue;
                }
                $valores[$cod] = [
                    'cantidad' => $nov->cantidad !== null ? (float) $nov->cantidad : null,
                    'importe' => $nov->importe !== null ? (float) $nov->importe : null,
                ];
            }
            // Importes efectivamente calculados (fórmulas de horas, etc.).
            foreach ($itemsCalculados->get($emp->id, collect()) as $it) {
                if ($it->codigo === 'PRESTAMO_CUOTA') {
                    continue;
                }
                $valores[$it->codigo] = ($valores[$it->codigo] ?? ['cantidad' => null, 'importe' => null])
                    + ['importe_calculado' => (float) $it->importe];
                $valores[$it->codigo]['importe_calculado'] = (float) $it->importe;
            }

            $prestamoCuota = (float) ($itemsCalculados->get($emp->id, collect())
                ->firstWhere('codigo', 'PRESTAMO_CUOTA')->importe ?? 0);

            // Días efectivos usados (override > prorrateo automático).
            $diasBase = 30;
            if ($emp->fecha_ingreso && $emp->fecha_ingreso->format('Y-m') === $liq->periodo) {
                $diasBase = max(0, 30 - ((int) $emp->fecha_ingreso->format('j') - 1));
            }
            $dias = $ov && $ov->dias_trabajados !== null ? (int) $ov->dias_trabajados : $diasBase;

            $comp = DB::table('erp_emp_composicion_sueldo')
                ->where('empleado_id', $emp->id)
                ->where('vigencia_desde', '<=', $cierre)
                ->where(fn ($q) => $q->whereNull('vigencia_hasta')->orWhere('vigencia_hasta', '>=', $cierre))
                ->orderByDesc('vigencia_desde')->first();
            $sumaOv = $ov ? round((float) $ov->porc_formal + (float) $ov->porc_efectivo + (float) $ov->porc_mt, 2) : 0.0;
            $repartoOverride = $ov && abs($sumaOv - 100.0) <= 0.01;
            $reparto = $repartoOverride
                ? ['porc_formal' => (float) $ov->porc_formal, 'porc_efectivo' => (float) $ov->porc_efectivo, 'porc_mt' => (float) $ov->porc_mt]
                : ['porc_formal' => (float) ($comp->porc_formal ?? 0), 'porc_efectivo' => (float) ($comp->porc_efectivo ?? 0), 'porc_mt' => (float) ($comp->porc_mt ?? 0)];

            $tot = $porEmpleado->get($emp->id);
            $netoEfectivo = round((float) ($tot->efectivo ?? 0), 2);
            [$efectivoRedondeado, $difRedondeo] = $this->pagos->redondearEfectivo(max(0, $netoEfectivo));

            $filas[] = [
                'empleado_id' => $emp->id,
                'legajo' => $emp->legajo,
                'nombre' => trim($emp->apellido.', '.$emp->nombre),
                'regimen' => $emp->regimen,
                'basico_vigente' => $basico !== null ? (float) $basico : null,
                'dias_trabajados' => $dias,
                'dias_override' => $ov?->dias_trabajados !== null ? (int) $ov->dias_trabajados : null,
                'valores' => $valores,
                'prestamo_cuota' => $prestamoCuota,
                'neto' => round((float) ($tot->neto ?? 0), 2),
                'formal' => round((float) ($tot->formal ?? 0), 2),
                'efectivo' => $netoEfectivo,
                'mt' => round((float) ($tot->mt ?? 0), 2),
                'reparto' => $reparto + ['override' => $repartoOverride],
                'efectivo_redondeado' => $efectivoRedondeado,
                'diferencia_redondeo' => $difRedondeo,
            ];
        }

        return [
            'liquidacion' => [
                'id' => $liq->id, 'periodo' => $liq->periodo, 'tipo' => $liq->tipo,
                'estado' => $liq->estado, 'editable' => $liq->esEditable(),
            ],
            'conceptos' => array_values(array_map(fn ($cod) => [
                'codigo' => $cod,
                'nombre' => $conceptos[$cod]->nombre ?? $cod,
                'signo' => $conceptos[$cod]->signo ?? 'HABER',
                'por_cantidad' => in_array($cod, self::POR_CANTIDAD, true),
            ], array_filter(self::CONCEPTOS_GRILLA, fn ($c) => isset($conceptos[$c])))),
            'filas' => $filas,
        ];
    }

    /** @param array<int, array<string, mixed>> $filas */
    public function guardar(Liquidacion $liq, array $filas, int $userId): array
    {
        if (! $liq->esEditable()) {
            throw new DomainException('LIQUIDACION_CERRADA: la grilla solo se edita en BORRADOR/CALCULADA (actual: '.$liq->estado.').');
        }

        $conceptos = Concepto::whereIn('codigo', self::CONCEPTOS_GRILLA)->get()->keyBy('codigo');

        DB::transaction(function () use ($liq, $filas, $conceptos, $userId) {
            foreach ($filas as $fila) {
                $empId = (int) ($fila['empleado_id'] ?? 0);
                if (! $empId) {
                    continue;
                }

                // Días + reparto → tabla de override.
                $tieneDias = array_key_exists('dias_trabajados', $fila);
                $reparto = $fila['reparto'] ?? null;
                if ($reparto !== null) {
                    $suma = round((float) $reparto['porc_formal'] + (float) $reparto['porc_efectivo'] + (float) $reparto['porc_mt'], 2);
                    if (abs($suma - 100.0) > 0.01) {
                        throw new DomainException("REPARTO_NO_SUMA_100: empleado #{$empId} suma {$suma}.");
                    }
                }
                if ($tieneDias || $reparto !== null) {
                    $update = ['updated_at' => now(), 'creado_por_id' => $userId];
                    if ($tieneDias) {
                        $update['dias_trabajados'] = $fila['dias_trabajados'] !== null
                            ? max(0, min(30, (int) $fila['dias_trabajados'])) : null;
                    }
                    if ($reparto !== null) {
                        $update += [
                            'porc_formal' => (float) $reparto['porc_formal'],
                            'porc_efectivo' => (float) $reparto['porc_efectivo'],
                            'porc_mt' => (float) $reparto['porc_mt'],
                        ];
                    }
                    DB::table('erp_emp_liquidacion_reparto_override')->updateOrInsert(
                        ['liquidacion_id' => $liq->id, 'empleado_id' => $empId],
                        $update + ['created_at' => now()],
                    );
                }

                // Valores → novedades del período (una por empleado+concepto).
                foreach (($fila['valores'] ?? []) as $codigo => $valor) {
                    $concepto = $conceptos->get($codigo);
                    if (! $concepto) {
                        continue;
                    }
                    $cantidad = isset($valor['cantidad']) && $valor['cantidad'] !== null ? (float) $valor['cantidad'] : null;
                    $importe = isset($valor['importe']) && $valor['importe'] !== null ? (float) $valor['importe'] : null;

                    $base = Novedad::where('empleado_id', $empId)
                        ->where('periodo', $liq->periodo)->where('concepto_id', $concepto->id);
                    if (($cantidad === null || $cantidad == 0.0) && ($importe === null || $importe == 0.0)) {
                        $base->delete();

                        continue;
                    }
                    $existente = $base->first();
                    if ($existente) {
                        $existente->update(['cantidad' => $cantidad ?? 0, 'importe' => $importe]);
                    } else {
                        Novedad::create([
                            'empleado_id' => $empId, 'periodo' => $liq->periodo,
                            'concepto_id' => $concepto->id, 'cantidad' => $cantidad ?? 0,
                            'importe' => $importe, 'creado_por_id' => $userId,
                        ]);
                    }
                }
            }
        });

        \App\Erp\Support\AuditoriaSueldos::log('GRILLA_GUARDADA', sprintf(
            'Grilla de liquidación #%d %s: %d fila(s) editadas por user #%d y recalculada.',
            $liq->id, $liq->periodo, count($filas), $userId));

        $this->liquidaciones->calcular($liq->fresh(), $userId);

        return $this->armar($liq->fresh());
    }
}
