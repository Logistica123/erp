<?php

namespace App\Erp\Http\Controllers\Af;

use App\Erp\Models\Ejercicio;
use App\Erp\Services\Af\AfReexpresionService;
use App\Erp\Services\Af\AfReportesService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reportes AF + reexpresión RT 6 (SPEC 06 §6.4 + §7.5).
 *
 *   GET  /af/reportes/listado                       inventario al corte
 *   GET  /af/reportes/anexo-bienes-uso?ejercicio=  RT 9
 *   GET  /af/reportes/altas-bajas?ejercicio=
 *   GET  /af/reportes/amortizaciones?ejercicio=    contable vs fiscal
 *
 *   POST /af/reexpresiones/generar  {ejercicio_id, indice_origen_default?}
 *   GET  /af/reexpresiones?ejercicio=
 */
class AfReportesController extends Controller
{
    public function __construct(
        private readonly AfReportesService $reportes,
        private readonly AfReexpresionService $reexp,
    ) {}

    public function listado(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'fecha'               => ['nullable', 'date'],
            'categoria_id'        => ['nullable', 'integer'],
            'centro_costo_id'     => ['nullable', 'integer'],
            'responsable_user_id' => ['nullable', 'integer'],
            'estado'              => ['nullable', 'in:ALTA,EN_REPARACION,PRESTADO,BAJA'],
        ]);
        $data = $this->reportes->listado(
            $this->empresaId($request),
            $datos['fecha'] ?? null,
            array_filter($datos, fn ($v, $k) => $k !== 'fecha' && $v !== null, ARRAY_FILTER_USE_BOTH)
        );
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function anexoBienesUso(Request $request): JsonResponse
    {
        $datos = $request->validate(['ejercicio_id' => ['required', 'integer']]);
        $ejercicio = $this->ejercicio((int) $datos['ejercicio_id'], $request);
        return response()->json(['ok' => true, 'data' => $this->reportes->anexoBienesUso($ejercicio)]);
    }

    public function altasBajas(Request $request): JsonResponse
    {
        $datos = $request->validate(['ejercicio_id' => ['required', 'integer']]);
        $ejercicio = $this->ejercicio((int) $datos['ejercicio_id'], $request);
        return response()->json(['ok' => true, 'data' => $this->reportes->altasBajas($ejercicio)]);
    }

    public function amortContVsFiscal(Request $request): JsonResponse
    {
        $datos = $request->validate(['ejercicio_id' => ['required', 'integer']]);
        $ejercicio = $this->ejercicio((int) $datos['ejercicio_id'], $request);
        return response()->json(['ok' => true, 'data' => $this->reportes->amortizacionesContableVsFiscal($ejercicio)]);
    }

    public function generarReexpresion(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'ejercicio_id'           => ['required', 'integer'],
            'indice_origen_default'  => ['nullable', 'numeric', 'min:0.000001'],
        ]);
        $ejercicio = $this->ejercicio((int) $datos['ejercicio_id'], $request);
        try {
            $res = $this->reexp->generar($ejercicio, $request->user(), $datos['indice_origen_default'] ?? null);
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];
            return response()->json([
                'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 409);
        }
        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function listarReexpresiones(Request $request): JsonResponse
    {
        $datos = $request->validate(['ejercicio_id' => ['required', 'integer']]);
        $ejercicio = $this->ejercicio((int) $datos['ejercicio_id'], $request);
        return response()->json(['ok' => true, 'data' => $this->reexp->listar($ejercicio)]);
    }

    private function ejercicio(int $id, Request $request): Ejercicio
    {
        return Ejercicio::where('empresa_id', $this->empresaId($request))->findOrFail($id);
    }

    private function empresaId(Request $request): int
    {
        return (int) ($request->header('X-Empresa-Id') ?: 1);
    }
}
