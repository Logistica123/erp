<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\Liquidacion;
use App\Erp\Models\Sueldos\Novedad;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Novedades del mes (SPEC 08 §5.3).
 *
 * Una novedad = (empleado × periodo × concepto) con cantidad/importe.
 * Si el período tiene una liquidación APROBADA o PAGADA, las novedades
 * de ese período son inmutables (RN-113 — la liquidación cerrada manda).
 *
 *   GET    /sueldos/novedades            ?periodo=YYYY-MM&empleado_id=&concepto_id=
 *   POST   /sueldos/novedades            alta individual
 *   POST   /sueldos/novedades/bulk       alta masiva (array items)
 *   DELETE /sueldos/novedades/{id}
 */
class NovedadesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.ver');

        $q = Novedad::with(['empleado:id,legajo,apellido,nombre', 'concepto:id,codigo,nombre,signo'])
            ->when($request->query('periodo'),     fn ($q, $v) => $q->where('periodo', $v))
            ->when($request->query('empleado_id'), fn ($q, $v) => $q->where('empleado_id', (int) $v))
            ->when($request->query('concepto_id'), fn ($q, $v) => $q->where('concepto_id', (int) $v))
            ->orderByDesc('periodo')->orderBy('empleado_id');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 100))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');

        $datos = $request->validate([
            'empleado_id'  => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'periodo'      => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'concepto_id'  => ['required', 'integer', 'exists:erp_emp_conceptos,id'],
            'cantidad'     => ['nullable', 'numeric'],
            'importe'      => ['nullable', 'numeric'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $this->verificarPeriodoAbierto($datos['periodo']);

        $datos['cantidad'] ??= 0;
        $datos['creado_por_id'] = $request->user()->id;
        $datos['created_at'] = now();
        $nov = Novedad::create($datos);

        return response()->json([
            'ok' => true,
            'data' => $nov->load(['empleado:id,legajo,apellido,nombre', 'concepto:id,codigo,nombre,signo']),
        ], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');

        $datos = $request->validate([
            'periodo' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'items'   => ['required', 'array', 'min:1'],
            'items.*.empleado_id'  => ['required', 'integer', 'exists:erp_emp_empleados,id'],
            'items.*.concepto_id'  => ['required', 'integer', 'exists:erp_emp_conceptos,id'],
            'items.*.cantidad'     => ['nullable', 'numeric'],
            'items.*.importe'      => ['nullable', 'numeric'],
            'items.*.observaciones' => ['nullable', 'string'],
        ]);

        $this->verificarPeriodoAbierto($datos['periodo']);

        $creadas = DB::transaction(function () use ($datos, $request) {
            $rows = [];
            $now = now();
            foreach ($datos['items'] as $it) {
                $rows[] = [
                    'empleado_id'   => $it['empleado_id'],
                    'periodo'       => $datos['periodo'],
                    'concepto_id'   => $it['concepto_id'],
                    'cantidad'      => $it['cantidad'] ?? 0,
                    'importe'       => $it['importe'] ?? null,
                    'observaciones' => $it['observaciones'] ?? null,
                    'creado_por_id' => $request->user()->id,
                    'created_at'    => $now,
                ];
            }
            DB::table('erp_emp_novedades')->insert($rows);
            return count($rows);
        });

        return response()->json(['ok' => true, 'data' => ['creadas' => $creadas]], 201);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.novedades.cargar');
        $nov = Novedad::findOrFail($id);
        $this->verificarPeriodoAbierto($nov->periodo);
        $nov->delete();
        return response()->json(['ok' => true]);
    }

    private function verificarPeriodoAbierto(string $periodo): void
    {
        $cerrada = Liquidacion::where('periodo', $periodo)
            ->whereIn('estado', [Liquidacion::ESTADO_APROBADA, Liquidacion::ESTADO_PAGADA])
            ->exists();
        if ($cerrada) {
            throw new DomainException('PERIODO_CERRADO: la liquidación de '.$periodo.' ya está aprobada/pagada. Usar liquidación rectificativa.');
        }
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}
