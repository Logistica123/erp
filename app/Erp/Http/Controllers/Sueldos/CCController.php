<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Sueldos\CC;
use App\Erp\Models\Sueldos\CCMovimiento;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas corrientes internas del empleado (SPEC 08 §5.4).
 *
 * Una CC por (empleado, tipo) — préstamo, adelanto, combustible, póliza,
 * sanción. Cada movimiento ajusta saldo_actual atómicamente. La
 * generación de asiento contable real queda para 8E (ahora solo persiste
 * el cargo / pago a nivel CC).
 *
 *   GET    /sueldos/cc                       ?empleado_id=&tipo=
 *   POST   /sueldos/cc                       crear CC para empleado×tipo
 *   GET    /sueldos/cc/{id}/movimientos
 *   POST   /sueldos/cc/{id}/movimientos      cargo/pago/ajuste manual
 */
class CCController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.ver');

        $q = CC::with(['empleado:id,legajo,apellido,nombre', 'cuenta:id,codigo,nombre'])
            ->when($request->query('empleado_id'), fn ($q, $v) => $q->where('empleado_id', (int) $v))
            ->when($request->query('tipo'),        fn ($q, $v) => $q->where('tipo', $v))
            ->when($request->boolean('solo_activas', true), fn ($q) => $q->where('activa', 1))
            ->orderBy('empleado_id')->orderBy('tipo');

        return response()->json(['ok' => true, 'data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.cargar');

        $datos = $request->validate([
            'empleado_id'        => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'tipo'               => ['required', 'in:PRESTAMO,ADELANTO,COMBUSTIBLE,POLIZA,SANCION,OTRO'],
            'cuenta_contable_id' => ['nullable', 'integer', 'exists:erp_cuentas_contables,id'],
            'limite_credito'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $datos['cuenta_contable_id'] ??= $this->cuentaPorTipo($datos['tipo']);
        if (! $datos['cuenta_contable_id']) {
            throw new DomainException('CUENTA_CONTABLE_REQUERIDA: el tipo '.$datos['tipo'].' no tiene cuenta default. Pasalo explícito.');
        }

        $cc = CC::create([
            'empleado_id'        => $datos['empleado_id'],
            'tipo'               => $datos['tipo'],
            'cuenta_contable_id' => $datos['cuenta_contable_id'],
            'saldo_actual'       => 0,
            'limite_credito'     => $datos['limite_credito'] ?? null,
            'activa'             => true,
        ]);

        return response()->json(['ok' => true, 'data' => $cc->load(['empleado:id,legajo,apellido,nombre', 'cuenta:id,codigo,nombre'])], 201);
    }

    public function movimientos(int $ccId, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.ver');

        CC::findOrFail($ccId);
        $rows = CCMovimiento::where('cc_id', $ccId)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function movimientoStore(int $ccId, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.cargar');

        $cc = CC::findOrFail($ccId);
        if (! $cc->activa) {
            throw new DomainException('CC_INACTIVA: la cuenta corriente está inactiva.');
        }

        $datos = $request->validate([
            'fecha'         => ['required', 'date'],
            'tipo_mov'      => ['required', 'in:CARGO,PAGO,DESCUENTO_LIQUIDACION,AJUSTE'],
            'importe'       => ['required', 'numeric', 'min:0.01'],
            'referencia'    => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($cc, $datos, $request) {
            // CARGO suma al saldo (deudor del empleado), PAGO/DESCUENTO_LIQUIDACION lo bajan.
            $delta = match ($datos['tipo_mov']) {
                CCMovimiento::TIPO_CARGO          => $datos['importe'],
                CCMovimiento::TIPO_PAGO,
                CCMovimiento::TIPO_DESCUENTO_LIQ  => -$datos['importe'],
                CCMovimiento::TIPO_AJUSTE         => $datos['importe'],
                default                            => 0.0,
            };
            $nuevoSaldo = round((float) $cc->saldo_actual + (float) $delta, 2);

            $mov = CCMovimiento::create([
                'cc_id'           => $cc->id,
                'fecha'           => $datos['fecha'],
                'tipo_mov'        => $datos['tipo_mov'],
                'importe'         => $datos['importe'],
                'saldo_posterior' => $nuevoSaldo,
                'referencia'      => $datos['referencia'] ?? null,
                'observaciones'   => $datos['observaciones'] ?? null,
                'creado_por_id'   => $request->user()->id,
                'created_at'      => now(),
            ]);

            $cc->update(['saldo_actual' => $nuevoSaldo]);

            return response()->json(['ok' => true, 'data' => $mov], 201);
        });
    }

    /**
     * Cuenta default por tipo de CC (mapeo opción C — códigos resueltos del 8A).
     */
    private function cuentaPorTipo(string $tipo): ?int
    {
        $codigo = match ($tipo) {
            CC::TIPO_PRESTAMO    => '1.1.5.10',
            CC::TIPO_ADELANTO    => '1.1.5.02',
            CC::TIPO_COMBUSTIBLE => '1.1.5.11',
            CC::TIPO_POLIZA      => '1.1.5.12',
            CC::TIPO_SANCION     => '1.1.5.13',
            default              => null,
        };
        if (! $codigo) {
            return null;
        }
        return CuentaContable::where('empresa_id', 1)->where('codigo', $codigo)->value('id');
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}
