<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\Ausencia;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ausencias del empleado (SPEC 08 §5.3): carpeta médica, vacaciones,
 * faltas, licencias. Una fila por evento. Las del mes alimentan la
 * liquidación (días no trabajados → descuentos / vacaciones gozadas).
 *
 *   GET    /sueldos/ausencias            ?empleado_id=&desde=&hasta=&tipo=
 *   POST   /sueldos/ausencias
 *   PUT    /sueldos/ausencias/{id}
 *   DELETE /sueldos/ausencias/{id}
 */
class AusenciasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');

        $q = Ausencia::with('empleado:id,legajo,apellido,nombre')
            ->when($request->query('empleado_id'), fn ($q, $v) => $q->where('empleado_id', (int) $v))
            ->when($request->query('tipo'),        fn ($q, $v) => $q->where('tipo', $v))
            ->when($request->query('desde'),       fn ($q, $v) => $q->where('fecha_hasta', '>=', $v))
            ->when($request->query('hasta'),       fn ($q, $v) => $q->where('fecha_desde', '<=', $v))
            ->orderByDesc('fecha_desde');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');

        $datos = $this->validate_($request);
        $datos['dias_habiles'] ??= $this->calcularDiasHabiles($datos['fecha_desde'], $datos['fecha_hasta']);
        $a = Ausencia::create($datos);
        return response()->json(['ok' => true, 'data' => $a->load('empleado:id,legajo,apellido,nombre')], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');

        $a = Ausencia::findOrFail($id);
        $datos = $this->validate_($request, partial: true);
        if (isset($datos['fecha_desde']) || isset($datos['fecha_hasta'])) {
            $desde = $datos['fecha_desde'] ?? $a->fecha_desde->format('Y-m-d');
            $hasta = $datos['fecha_hasta'] ?? $a->fecha_hasta->format('Y-m-d');
            if (! isset($datos['dias_habiles'])) {
                $datos['dias_habiles'] = $this->calcularDiasHabiles($desde, $hasta);
            }
        }
        $a->update($datos);
        return response()->json(['ok' => true, 'data' => $a->fresh('empleado:id,legajo,apellido,nombre')]);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');
        Ausencia::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    private function validate_(Request $request, bool $partial = false): array
    {
        $rules = [
            'empleado_id'    => [$partial ? 'nullable' : 'required', 'integer', 'exists:erp_emp_empleados,id'],
            'tipo'           => [$partial ? 'nullable' : 'required', 'in:CARPETA_MEDICA,LICENCIA_ESPECIAL,VACACIONES,FALTA_INJUSTIFICADA,SUSPENSION,OTROS'],
            'fecha_desde'    => [$partial ? 'nullable' : 'required', 'date'],
            'fecha_hasta'    => [$partial ? 'nullable' : 'required', 'date', 'after_or_equal:fecha_desde'],
            'dias_habiles'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'paga'           => ['nullable', 'boolean'],
            'observaciones'  => ['nullable', 'string'],
            'adjunto_path'   => ['nullable', 'string', 'max:400'],
        ];
        return $request->validate($rules);
    }

    /**
     * Días hábiles aproximados (lun-vie) entre dos fechas inclusivas.
     * No considera feriados — eso queda como override manual.
     */
    private function calcularDiasHabiles(string $desde, string $hasta): int
    {
        $d = new \DateTime($desde);
        $h = new \DateTime($hasta);
        $count = 0;
        while ($d <= $h) {
            if ((int) $d->format('N') < 6) {
                $count++;
            }
            $d->modify('+1 day');
        }
        return $count;
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}
