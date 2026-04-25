<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\CC;
use App\Erp\Models\Sueldos\CCMovimiento;
use App\Erp\Models\Sueldos\Prestamo;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Préstamos al personal con plan de cuotas (SPEC 08 §5.4 / §5.5).
 *
 * Otorgar un préstamo:
 *   - crea el plan en erp_emp_prestamos (estado=VIGENTE).
 *   - garantiza CC tipo=PRESTAMO del empleado.
 *   - genera movimiento CARGO en la CC por el capital.
 *   - el asiento contable de alta queda diferido a 8E (asiento_alta_id).
 *
 * El descuento mensual de la cuota lo aplica la liquidación (8D)
 * generando un CCMovimiento DESCUENTO_LIQUIDACION + actualizando
 * cuotas_pagadas y saldo_capital.
 *
 *   GET    /sueldos/prestamos                ?empleado_id=&estado=
 *   POST   /sueldos/prestamos                otorgar
 *   GET    /sueldos/prestamos/{id}
 */
class PrestamosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.ver');

        $q = Prestamo::with(['empleado:id,legajo,apellido,nombre', 'aprobador:id,name'])
            ->when($request->query('empleado_id'), fn ($q, $v) => $q->where('empleado_id', (int) $v))
            ->when($request->query('estado'),      fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('fecha_otorgamiento');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.cc.ver');
        $p = Prestamo::with(['empleado:id,legajo,apellido,nombre', 'aprobador:id,name'])->findOrFail($id);
        return response()->json(['ok' => true, 'data' => $p]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.prestamos.otorgar');

        $datos = $request->validate([
            'empleado_id'           => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'fecha_otorgamiento'    => ['required', 'date'],
            'capital'               => ['required', 'numeric', 'min:0.01'],
            'cuotas_total'          => ['required', 'integer', 'min:1', 'max:120'],
            'primera_cuota_periodo' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'observaciones'         => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($datos, $request) {
            $cuotaMensual = round((float) $datos['capital'] / (int) $datos['cuotas_total'], 2);

            $prestamo = Prestamo::create([
                'empleado_id'           => $datos['empleado_id'],
                'fecha_otorgamiento'    => $datos['fecha_otorgamiento'],
                'capital'               => $datos['capital'],
                'cuotas_total'          => $datos['cuotas_total'],
                'cuotas_pagadas'        => 0,
                'cuota_mensual'         => $cuotaMensual,
                'saldo_capital'         => $datos['capital'],
                'primera_cuota_periodo' => $datos['primera_cuota_periodo'],
                'estado'                => Prestamo::ESTADO_VIGENTE,
                'aprobado_por_id'       => $request->user()->id,
                'observaciones'         => $datos['observaciones'] ?? null,
            ]);

            // Garantizar CC tipo PRESTAMO del empleado.
            $cc = CC::firstOrCreate(
                ['empleado_id' => $datos['empleado_id'], 'tipo' => CC::TIPO_PRESTAMO],
                [
                    'cuenta_contable_id' => $this->cuentaPrestamos(),
                    'saldo_actual'       => 0,
                    'activa'             => true,
                ]
            );

            // CARGO en la CC por el capital del préstamo.
            $nuevoSaldo = round((float) $cc->saldo_actual + (float) $datos['capital'], 2);
            CCMovimiento::create([
                'cc_id'           => $cc->id,
                'fecha'           => $datos['fecha_otorgamiento'],
                'tipo_mov'        => CCMovimiento::TIPO_CARGO,
                'importe'         => $datos['capital'],
                'saldo_posterior' => $nuevoSaldo,
                'referencia'      => 'Préstamo #'.$prestamo->id,
                'creado_por_id'   => $request->user()->id,
                'created_at'      => now(),
            ]);
            $cc->update(['saldo_actual' => $nuevoSaldo]);

            return response()->json(['ok' => true, 'data' => $prestamo->load(['empleado:id,legajo,apellido,nombre', 'aprobador:id,name'])], 201);
        });
    }

    private function cuentaPrestamos(): int
    {
        $id = \App\Erp\Models\CuentaContable::where('empresa_id', 1)->where('codigo', '1.1.5.10')->value('id');
        if (! $id) {
            throw new DomainException('CUENTA_PRESTAMOS_NO_ENCONTRADA: falta seedear 1.1.5.10');
        }
        return (int) $id;
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}
