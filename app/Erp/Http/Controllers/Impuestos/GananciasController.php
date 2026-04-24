<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\GananciaAnticipo;
use App\Erp\Models\Impuestos\GananciaLiquidacion;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\GananciasAnticiposService;
use App\Erp\Services\Impuestos\GananciasCalculator;
use App\Erp\Services\Impuestos\GananciasF713Service;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ganancias F.713 + anticipos (SPEC 05 §6.6).
 *
 *   GET  /impuestos/ganancias/{ejercicio_id}                         — detalle
 *   POST /impuestos/ganancias/{ejercicio_id}/calcular                — base + escala
 *   POST /impuestos/ganancias/{ejercicio_id}/agregar-ajuste          — MAS/MENOS
 *   POST /impuestos/ganancias/{ejercicio_id}/generar-f713            — TXT
 *   GET  /impuestos/ganancias/{ejercicio_id}/descargar               — download
 *   POST /impuestos/ganancias/{ejercicio_id}/generar-anticipos       — 10 anticipos
 *   GET  /impuestos/ganancias/anticipos                              — listado
 *   POST /impuestos/ganancias/anticipos/{id}/pagar                   — marca pagado
 */
class GananciasController extends Controller
{
    public function __construct(
        private readonly GananciasCalculator $calculator,
        private readonly GananciasAnticiposService $anticipos,
        private readonly GananciasF713Service $f713,
    ) {}

    public function show(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $liq = GananciaLiquidacion::with('anticipos')
            ->where('ejercicio_id', $ejercicio->id)
            ->first();

        return response()->json(['ok' => true, 'data' => [
            'ejercicio' => $ejercicio, 'liquidacion' => $liq,
        ]]);
    }

    public function calcular(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $periodo = $this->periodoAnual($ejercicio, $request);

        $datos = $request->validate([
            'ajuste_inflacion'     => ['nullable', 'numeric'],
            'retenciones_sufridas' => ['nullable', 'numeric', 'min:0'],
            'percepciones_sufridas'=> ['nullable', 'numeric', 'min:0'],
            'anticipos_computados' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $liq = $this->calculator->calcular($periodo, $ejercicio, $request->user(), $datos);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $liq]);
    }

    public function agregarAjuste(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $liq = GananciaLiquidacion::where('ejercicio_id', $ejercicio->id)->firstOrFail();

        $datos = $request->validate([
            'tipo'        => ['required', 'in:MAS,MENOS'],
            'concepto'    => ['required', 'string', 'max:100'],
            'importe'     => ['required', 'numeric', 'min:0'],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $liq = $this->calculator->agregarAjuste($liq, $datos, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $liq]);
    }

    public function generar(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $liq = GananciaLiquidacion::where('ejercicio_id', $ejercicio->id)->firstOrFail();
        $periodo = $liq->periodo;

        try {
            $res = $this->f713->generar($periodo, $liq, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function descargar(int $ejercicioId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $liq = GananciaLiquidacion::where('ejercicio_id', $ejercicio->id)->first();
        if (! $liq || ! $liq->archivo_f713_path || ! Storage::disk('local')->exists($liq->archivo_f713_path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'F713_NO_GENERADO']], 404);
        }

        $anio = $liq->periodo->anio;
        return Storage::disk('local')->download($liq->archivo_f713_path, "F713_{$anio}.txt");
    }

    public function generarAnticipos(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $liq = GananciaLiquidacion::where('ejercicio_id', $ejercicio->id)->firstOrFail();

        try {
            $list = $this->anticipos->generar($liq, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $list], Response::HTTP_CREATED);
    }

    public function listarAnticipos(Request $request): JsonResponse
    {
        $q = GananciaAnticipo::query()
            ->when($request->query('ejercicio_id'), fn ($q, $v) => $q->where('ejercicio_id', (int) $v))
            ->when($request->query('estado'),       fn ($q, $v) => $q->where('estado', $v))
            ->orderBy('fecha_vencimiento');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function pagarAnticipo(int $id, Request $request): JsonResponse
    {
        $datos = $request->validate(['orden_pago_id' => ['required', 'integer']]);
        $anticipo = GananciaAnticipo::findOrFail($id);

        try {
            $anticipo = $this->anticipos->pagar($anticipo, (int) $datos['orden_pago_id'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $anticipo]);
    }

    private function ejercicio(int $id, Request $request): Ejercicio
    {
        return Ejercicio::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
    }

    private function periodoAnual(Ejercicio $ejercicio, Request $request): PeriodoFiscal
    {
        $periodo = PeriodoFiscal::where('empresa_id', $ejercicio->empresa_id)
            ->where('impuesto', 'GAN_ANUAL')
            ->where('ejercicio_id', $ejercicio->id)
            ->whereNull('rectifica_a_id')
            ->first();

        if (! $periodo) {
            abort(404, 'Período GAN_ANUAL no existe — crear primero via /periodos');
        }
        return $periodo;
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}
