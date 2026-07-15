<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\Empleado;
use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\LiquidacionItem;
use App\Erp\Services\Sueldos\LiquidacionService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Liquidación mensual (SPEC 08 §5.5 + §6 + §9).
 *
 *   GET    /sueldos/liquidaciones                ?periodo=&estado=&tipo=
 *   POST   /sueldos/liquidaciones                crear (BORRADOR)
 *   GET    /sueldos/liquidaciones/{id}           cabecera
 *   POST   /sueldos/liquidaciones/{id}/calcular  BORRADOR → CALCULADA
 *   POST   /sueldos/liquidaciones/{id}/aprobar   CALCULADA → APROBADA
 *   POST   /sueldos/liquidaciones/{id}/anular    * → ANULADA (sensible)
 *   POST   /sueldos/liquidaciones/{id}/rectificar APROBADA/PAGADA → nueva BORRADOR
 *   GET    /sueldos/liquidaciones/{id}/items     ?empleado_id=&componente=
 *   GET    /sueldos/liquidaciones/{id}/recibo/{empleado_id}  recibo HTML
 */
class LiquidacionesController extends Controller
{
    public function __construct(private readonly LiquidacionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $q = Liquidacion::with('asiento:id,numero,fecha')
            ->when($request->query('periodo'), fn ($q, $v) => $q->where('periodo', $v))
            ->when($request->query('estado'),  fn ($q, $v) => $q->where('estado', $v))
            ->when($request->query('tipo'),    fn ($q, $v) => $q->where('tipo', $v))
            ->orderByDesc('periodo')->orderByDesc('id');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.calcular');
        $datos = $request->validate([
            'periodo' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'tipo'    => ['required', 'in:MENSUAL,SAC,AJUSTE,FINAL'],
            'observaciones' => ['nullable', 'string'],
        ]);
        $existe = Liquidacion::where('periodo', $datos['periodo'])->where('tipo', $datos['tipo'])->first();
        if ($existe) {
            throw new DomainException('LIQUIDACION_DUPLICADA: ya existe una '.$datos['tipo'].' para '.$datos['periodo'].' (#'.$existe->id.', '.$existe->estado.')');
        }
        $liq = Liquidacion::create([
            'periodo' => $datos['periodo'],
            'tipo'    => $datos['tipo'],
            'estado'  => Liquidacion::ESTADO_BORRADOR,
            'observaciones' => $datos['observaciones'] ?? null,
        ]);

        \App\Erp\Support\AuditoriaSueldos::log('LIQUIDACION_CREADA', sprintf(
            'Liquidación #%d %s (%s) creada por user #%d.',
            $liq->id, $liq->periodo, $liq->tipo, $request->user()->id));
        return response()->json(['ok' => true, 'data' => $liq], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $liq = Liquidacion::with(['calculador:id,name', 'aprobador:id,name', 'asiento:id,numero,fecha'])
            ->findOrFail($id);

        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;
        $data = $liq->toArray();
        if (! $verEfectivos) {
            unset($data['total_efectivo']);
        }
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function calcular(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.calcular');
        $liq = Liquidacion::findOrFail($id);

        try {
            $liq = $this->service->calcular($liq, $request->user()->id);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $liq]);
    }

    public function aprobar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.aprobar');
        $liq = Liquidacion::findOrFail($id);
        // G-03: aprobar sella el snapshot con hash de integridad (service).
        $liq = app(\App\Erp\Services\Sueldos\LiquidacionService::class)
            ->aprobar($liq, $request->user()->id);
        return response()->json(['ok' => true, 'data' => $liq]);
    }

    public function anular(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.reabrir');
        $liq = Liquidacion::findOrFail($id);
        if (in_array($liq->estado, [Liquidacion::ESTADO_PAGADA, Liquidacion::ESTADO_RECTIFICADA, Liquidacion::ESTADO_ANULADA], true)) {
            throw new DomainException('ESTADO_INVALIDO: no se puede anular una liquidación '.$liq->estado.'.');
        }
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);
        DB::transaction(function () use ($liq, $datos) {
            LiquidacionItem::where('liquidacion_id', $liq->id)->delete();
            $liq->update([
                'estado' => Liquidacion::ESTADO_ANULADA,
                'observaciones' => trim(($liq->observaciones ?? '').' [ANULADA: '.$datos['motivo'].']'),
                'total_bruto' => 0, 'total_descuentos' => 0, 'total_neto' => 0,
                'total_formal' => 0, 'total_efectivo' => 0, 'total_mt' => 0,
                'empleados_count' => 0,
            ]);
        });
        \App\Erp\Support\AuditoriaSueldos::log('LIQUIDACION_ANULADA', sprintf(
            'Liquidación #%d anulada por user #%d. Motivo: %s',
            $id, $request->user()->id, $datos['motivo'] ?? '-'));

        return response()->json(['ok' => true, 'data' => $liq->fresh()]);
    }

    public function rectificar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.reabrir');
        $original = Liquidacion::findOrFail($id);
        if (! in_array($original->estado, [Liquidacion::ESTADO_APROBADA, Liquidacion::ESTADO_PAGADA], true)) {
            throw new DomainException('ESTADO_INVALIDO: solo se rectifican liquidaciones APROBADA/PAGADA (actual: '.$original->estado.').');
        }
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        $nueva = DB::transaction(function () use ($original, $datos) {
            $original->update(['estado' => Liquidacion::ESTADO_RECTIFICADA]);
            return Liquidacion::create([
                'periodo'               => $original->periodo,
                'tipo'                  => Liquidacion::TIPO_AJUSTE,
                'estado'                => Liquidacion::ESTADO_BORRADOR,
                'liquidacion_origen_id' => $original->id,
                'observaciones'         => 'Rectificativa de #'.$original->id.' — '.$datos['motivo'],
            ]);
        });

        \App\Erp\Support\AuditoriaSueldos::log('LIQUIDACION_RECTIFICADA', sprintf(
            'Liquidación #%d marcada RECTIFICADA por user #%d (nueva liquidación AJUSTE encadenada).',
            $id, $request->user()->id));

        return response()->json(['ok' => true, 'data' => $nueva], 201);
    }

    public function items(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $liq = Liquidacion::findOrFail($id);

        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        $q = LiquidacionItem::with(['empleado:id,legajo,apellido,nombre', 'concepto:id,codigo,nombre,signo,tipo'])
            ->where('liquidacion_id', $liq->id)
            ->when($request->query('empleado_id'), fn ($q, $v) => $q->where('empleado_id', (int) $v))
            ->when($request->query('componente'),  fn ($q, $v) => $q->where('componente', $v))
            ->orderBy('empleado_id');

        if (! $verEfectivos) {
            $q->where('componente', '!=', LiquidacionItem::COMPONENTE_EFECTIVO);
        }

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 200))]);
    }

    public function recibo(int $id, int $empleadoId, Request $request): Response
    {
        $this->mustHave($request, 'sueldos.liquidaciones.ver');
        $liq = Liquidacion::findOrFail($id);
        $emp = Empleado::with(['categoria.convenio'])->findOrFail($empleadoId);

        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        $items = LiquidacionItem::with('concepto')
            ->where('liquidacion_id', $liq->id)
            ->where('empleado_id', $emp->id)
            ->when(! $verEfectivos, fn ($q) => $q->where('componente', '!=', LiquidacionItem::COMPONENTE_EFECTIVO))
            ->orderBy('concepto_id')
            ->get();

        $totales = ['haberes' => 0.0, 'descuentos' => 0.0, 'formal' => 0.0, 'efectivo' => 0.0, 'mt' => 0.0];
        foreach ($items as $it) {
            $signo = $it->concepto->signo;
            $imp = (float) $it->importe;
            if ($signo === 'HABER')      $totales['haberes']    += $imp;
            else                         $totales['descuentos'] += $imp;
            if ($it->componente === 'FORMAL')   $totales['formal']   += ($signo === 'HABER' ? $imp : -$imp);
            if ($it->componente === 'EFECTIVO') $totales['efectivo'] += ($signo === 'HABER' ? $imp : -$imp);
            if ($it->componente === 'MT')       $totales['mt']       += ($signo === 'HABER' ? $imp : -$imp);
        }
        $totales['neto'] = $totales['haberes'] - $totales['descuentos'];

        $html = view('sueldos.recibo', compact('liq', 'emp', 'items', 'totales', 'verEfectivos'))->render();
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json(['ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()]], 409);
    }

    /**
     * G-07 (P1) — guardar el override de reparto FORMAL/EFECTIVO/MT de un
     * empleado para ESTA liquidación (no toca el maestro) y recalcular.
     */
    public function repartoGuardar(int $id, int $empleadoId, Request $request): JsonResponse
    {
        $liq = Liquidacion::findOrFail($id);
        if (! in_array($liq->estado, [Liquidacion::ESTADO_BORRADOR, Liquidacion::ESTADO_CALCULADA], true)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'LIQUIDACION_CERRADA',
                'message' => "El reparto solo se edita en BORRADOR/CALCULADA (actual: {$liq->estado}).",
            ]], 422);
        }

        $data = $request->validate([
            'porc_formal' => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_efectivo' => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_mt' => ['required', 'numeric', 'min:0', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:300'],
        ]);
        $suma = round($data['porc_formal'] + $data['porc_efectivo'] + $data['porc_mt'], 2);
        if (abs($suma - 100.0) > 0.01) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'REPARTO_NO_SUMA_100',
                'message' => "Los porcentajes deben sumar 100 (suman {$suma}).",
            ]], 422);
        }

        DB::table('erp_emp_liquidacion_reparto_override')->updateOrInsert(
            ['liquidacion_id' => $liq->id, 'empleado_id' => $empleadoId],
            [
                'porc_formal' => $data['porc_formal'], 'porc_efectivo' => $data['porc_efectivo'],
                'porc_mt' => $data['porc_mt'], 'observaciones' => $data['observaciones'] ?? null,
                'creado_por_id' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
            ]
        );

        // Recalcular para que el reparto quede aplicado de inmediato.
        $liq = app(\App\Erp\Services\Sueldos\LiquidacionService::class)
            ->calcular($liq->fresh(), $request->user()->id);

        return response()->json(['ok' => true, 'data' => $liq]);
    }

    /** G-07 — quitar el override (vuelve al default del maestro) y recalcular. */
    public function repartoQuitar(int $id, int $empleadoId, Request $request): JsonResponse
    {
        $liq = Liquidacion::findOrFail($id);
        if (! in_array($liq->estado, [Liquidacion::ESTADO_BORRADOR, Liquidacion::ESTADO_CALCULADA], true)) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'LIQUIDACION_CERRADA',
                'message' => "El reparto solo se edita en BORRADOR/CALCULADA (actual: {$liq->estado}).",
            ]], 422);
        }

        DB::table('erp_emp_liquidacion_reparto_override')
            ->where('liquidacion_id', $liq->id)->where('empleado_id', $empleadoId)->delete();

        $liq = app(\App\Erp\Services\Sueldos\LiquidacionService::class)
            ->calcular($liq->fresh(), $request->user()->id);

        return response()->json(['ok' => true, 'data' => $liq]);
    }

    /** Bloque 3 (P8) — grilla estilo Excel: una fila por empleado. */
    public function grilla(int $id, Request $request): JsonResponse
    {
        $liq = Liquidacion::findOrFail($id);

        return response()->json(['ok' => true,
            'data' => app(\App\Erp\Services\Sueldos\GrillaSueldosService::class)->armar($liq)]);
    }

    /** Bloque 3 (P8) — guarda la grilla completa (días/reparto/valores) y recalcula. */
    public function grillaGuardar(int $id, Request $request): JsonResponse
    {
        $liq = Liquidacion::findOrFail($id);
        $data = $request->validate(['filas' => ['required', 'array', 'min:1']]);

        try {
            $grilla = app(\App\Erp\Services\Sueldos\GrillaSueldosService::class)
                ->guardar($liq, $data['filas'], $request->user()->id);
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];

            return response()->json(['ok' => false, 'error' => [
                'code' => $code, 'message' => $e->getMessage(),
            ]], 422);
        }

        return response()->json(['ok' => true, 'data' => $grilla]);
    }
}
